<?php

namespace App\Services\Payments\Tamara;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\PaymentTransactionType;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Models\Payment;
use App\Services\CustomerMailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Orchestrates the order <-> Tamara (BNPL) lifecycle.
 *
 * Same safety model as the Moyasar PaymentService: a webhook/redirect only
 * TRIGGERS a confirmation; the truth comes from re-fetching the Tamara order and
 * verifying status + amount + currency. All transitions are row-locked + idempotent.
 *
 * Retab flow (differs from a typical immediate-capture setup): on approval we
 * AUTHORISE (commit the hold) and move the order to `awaiting_confirmation` — the
 * money is NOT taken yet. capture() takes the money at ADMIN CONFIRMATION; void()
 * releases the hold if we can't fulfill. Tamara works in MAJOR units (100.00 SAR).
 */
class TamaraService
{
    public function __construct(
        protected TamaraClient $client,
    ) {}

    /**
     * Create a Tamara checkout session and return the redirect URL. Reuses an
     * existing session while the order is still payable.
     */
    public function initiate(Order $order): string
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            throw new \RuntimeException('Order is already paid.');
        }

        if ($order->payment_url && $order->gateway_reference) {
            return $order->payment_url;
        }

        $order->loadMissing('items');

        $session = $this->client->createCheckout($this->buildCheckoutPayload($order));

        $order->forceFill([
            'payment_gateway' => 'tamara',
            'payment_method' => PaymentMethod::Tamara,
            'gateway_reference' => $session['order_id'],
            'payment_url' => $session['checkout_url'],
        ])->save();

        return $session['checkout_url'];
    }

    /**
     * Authoritative, idempotent. Re-fetch the Tamara order, verify it, AUTHORISE
     * (hold the funds — not captured), and move our order to awaiting_confirmation.
     */
    public function confirm(string $tamaraOrderId): ?Order
    {
        $remote = $this->client->getOrder($tamaraOrderId);

        $order = Order::where('gateway_reference', $tamaraOrderId)->first();
        if (! $order) {
            Log::warning('Tamara order could not be matched locally', ['tamara_order_id' => $tamaraOrderId]);

            return null;
        }

        return DB::transaction(function () use ($order, $remote, $tamaraOrderId) {
            $order = Order::whereKey($order->id)->lockForUpdate()->first();

            // Idempotency: this order already reached a final payment state.
            $settled = Payment::where('order_id', $order->id)
                ->whereIn('status', ['authorized', 'succeeded', 'failed'])
                ->exists();
            if ($settled) {
                return $order;
            }

            $status = (string) ($remote['status'] ?? 'new');
            $amountOk = $this->remoteMinorAmount($remote) === $this->expectedMinorAmount($order);
            $currencyOk = strtoupper((string) ($remote['total_amount']['currency'] ?? '')) === $this->currency();

            // Terminal failure states.
            if (in_array($status, ['declined', 'expired', 'canceled', 'cancelled'], true)) {
                $this->recordTransaction($order, PaymentTransactionType::Void, 'failed', $tamaraOrderId, $remote);
                $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();

                return $order;
            }

            $committed = in_array($status, ['authorised', 'fully_captured', 'partially_captured'], true);

            if ($status === 'approved' || $committed) {
                if (! $amountOk || ! $currencyOk) {
                    Log::error('Tamara order failed amount/currency verification', [
                        'order_id' => $order->id,
                        'tamara_order_id' => $tamaraOrderId,
                        'expected' => $this->expectedMinorAmount($order),
                        'got' => $this->remoteMinorAmount($remote),
                    ]);
                    $this->recordTransaction($order, PaymentTransactionType::Authorization, 'mismatch', $tamaraOrderId, $remote);

                    return $order;
                }

                // 'approved' must be authorised before the hold is committed.
                if ($status === 'approved') {
                    $this->client->authorise($tamaraOrderId);
                }

                // Funds HELD, not captured — order now awaits the admin's inventory check.
                $this->recordTransaction($order, PaymentTransactionType::Authorization, 'authorized', $tamaraOrderId, $remote);
                $this->markAuthorized($order);

                return $order;
            }

            // status 'new' / pending — nothing to do yet.
            return $order;
        });
    }

    /**
     * Capture an authorised Tamara order — called at ADMIN CONFIRMATION (money
     * taken now). Idempotent: already-captured orders are skipped.
     */
    public function capture(Order $order, array $shippingInfo = []): void
    {
        if ($order->payment_gateway !== 'tamara' || ! $order->gateway_reference) {
            return;
        }

        $alreadyCaptured = Payment::where('order_id', $order->id)
            ->where('type', PaymentTransactionType::Capture->value)
            ->where('status', 'succeeded')
            ->exists();
        if ($alreadyCaptured) {
            return;
        }

        $remote = $this->client->getOrder($order->gateway_reference);

        if ((string) ($remote['status'] ?? '') !== 'fully_captured') {
            $this->client->capture($order->gateway_reference, [
                'total_amount' => $this->money((float) $order->total),
                'shipping_info' => array_merge([
                    'shipped_at' => now()->toIso8601String(),
                    'shipping_company' => $order->carrier ?: 'Standard',
                ], array_filter($shippingInfo)),
            ]);
        }

        $this->recordTransaction($order, PaymentTransactionType::Capture, 'succeeded', $order->gateway_reference.'-capture', $remote);

        if ($order->payment_status !== PaymentStatus::Paid) {
            $order->forceFill(['payment_status' => PaymentStatus::Paid, 'paid_at' => now()])->save();
        }
    }

    /**
     * Statuses Tamara reports for an order whose hold can no longer be captured.
     *
     * ⚠️ Read from Tamara's documented order lifecycle, NOT verified against a live
     * response. That is why `reconcileLapsed()` treats an unrecognised status as
     * "still fine" — marking a live order dead is far worse than being slow to
     * notice a dead one, so the unknown case must fail in the safe direction.
     */
    private const TERMINAL = ['expired', 'canceled', 'cancelled', 'declined'];

    /**
     * Ask Tamara whether a hold we still believe is live has actually lapsed, and
     * record reality if it has.
     *
     * 🔑 We otherwise only discover an expired hold when a human clicks Confirm and
     * the capture throws — until then the order sits on the dashboard looking
     * actionable when the sale is already gone.
     *
     * 🔑 The order is moved to Failed + PendingPayment rather than cancelled,
     * because that is exactly the shape the existing resume-payment route already
     * knows how to restart (see CheckoutController::pay, which clears the dead
     * session and mints a fresh one). Cancelling would destroy an order the
     * customer still wants; this keeps their items and address and only marks the
     * payment as needing another go.
     *
     * Returns the remote status when it acted, null when the hold is still good or
     * Tamara could not be reached.
     */
    /** Fetch the remote order. Extracted so tests can report a status without HTTP. */
    protected function remoteOrder(string $reference): array
    {
        return $this->client->getOrder($reference);
    }

    public function reconcileLapsed(Order $order): ?string
    {
        if ($order->payment_gateway !== 'tamara' || ! $order->gateway_reference) {
            return null;
        }
        if ($order->payment_status !== PaymentStatus::Authorized) {
            return null; // already captured, refunded or otherwise resolved
        }

        try {
            $remote = $this->remoteOrder($order->gateway_reference);
        } catch (\Throwable $e) {
            // A network blip must not be read as an expiry.
            Log::warning('Tamara status check failed', ['order' => $order->order_number, 'error' => $e->getMessage()]);

            return null;
        }

        $status = strtolower((string) ($remote['status'] ?? ''));
        if (! in_array($status, self::TERMINAL, true)) {
            return null;
        }

        $order->forceFill([
            'payment_status' => PaymentStatus::Failed,
            'status' => OrderStatus::PendingPayment,
        ])->save();

        // `payment_url` and `gateway_reference` are deliberately left in place —
        // CheckoutController::pay() clears them when it restarts a Failed order, so
        // duplicating that here would be two places to keep in step.
        OrderActivity::create([
            'order_id' => $order->id,
            'type' => 'payment_lapsed',
            'from_status' => OrderStatus::AwaitingConfirmation->value,
            'to_status' => OrderStatus::PendingPayment->value,
            'meta' => ['gateway' => 'tamara', 'remote_status' => $status],
        ]);

        return $status;
    }

    /**
     * Release the hold on an authorised order (when we can't fulfill). Refuses to
     * void an already-captured order — that needs a refund instead.
     */
    public function void(Order $order): void
    {
        if ($order->payment_gateway !== 'tamara' || ! $order->gateway_reference) {
            return;
        }
        if ($order->payment_status === PaymentStatus::Paid) {
            return;
        }

        try {
            $this->client->cancel($order->gateway_reference, [
                'total_amount' => $this->money((float) $order->total),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Tamara cancel failed', ['order_id' => $order->id, 'error' => $e->getMessage()]);
        }

        $this->recordTransaction($order, PaymentTransactionType::Void, 'voided', $order->gateway_reference.'-void', []);
        $order->forceFill(['payment_status' => PaymentStatus::Voided])->save();
    }

    /**
     * Refund (full or partial) a captured Tamara order. Records a Refund ledger
     * row and moves payment_status to Refunded/PartiallyRefunded. Amount in SAR.
     */
    public function refund(Order $order, float $amount): void
    {
        if ($order->payment_gateway !== 'tamara' || ! $order->gateway_reference) {
            throw new \RuntimeException('Order has no Tamara payment to refund.');
        }
        if (! in_array($order->payment_status, [PaymentStatus::Paid, PaymentStatus::PartiallyRefunded], true)) {
            throw new \RuntimeException('Only captured Tamara orders can be refunded.');
        }

        $remote = $this->client->refund($order->gateway_reference, [
            'total_amount' => $this->money($amount),
            'comment' => "Return refund for order {$order->order_number}",
        ]);

        // Direct create (not recordTransaction): the ledger must carry the actual
        // refunded amount, which may be partial — recordTransaction assumes total.
        Payment::create([
            'order_id' => $order->id,
            'gateway' => 'tamara',
            'gateway_transaction_id' => $order->gateway_reference.'-refund-'.now()->format('YmdHis'),
            'type' => PaymentTransactionType::Refund,
            'amount' => round($amount, 2),
            'currency' => $this->currency(),
            'status' => 'succeeded',
            'raw' => $remote,
        ]);

        $refunded = (float) Payment::where('order_id', $order->id)
            ->where('type', PaymentTransactionType::Refund->value)
            ->where('status', 'succeeded')
            ->sum('amount');

        $order->forceFill([
            'payment_status' => $refunded >= (float) $order->total
                ? PaymentStatus::Refunded
                : PaymentStatus::PartiallyRefunded,
        ])->save();
    }

    private function markAuthorized(Order $order): void
    {
        // ⚠️ Unlike PaymentService::markOrderPaid this method has no early return,
        // so it re-runs on every repeated webhook. Capture the first transition
        // explicitly — otherwise the customer gets a receipt per delivery attempt.
        $firstAuthorization = $order->status === OrderStatus::PendingPayment;

        $order->forceFill([
            'payment_status' => PaymentStatus::Authorized,
            'payment_method' => PaymentMethod::Tamara,
            'status' => $firstAuthorization
                ? OrderStatus::AwaitingConfirmation
                : $order->status,
        ])->save();

        if ($firstAuthorization) {
            // Gated on the first transition for the same reason the receipt is:
            // this method re-runs on every repeated webhook delivery.
            OrderActivity::logPaymentAuthorized(
                $order,
                OrderStatus::PendingPayment->value,
                'tamara',
                $order->total,
                $order->currency,
            );

            app(CustomerMailer::class)->orderPlaced($order);
        }
    }

    private function recordTransaction(Order $order, PaymentTransactionType $type, string $status, string $transactionId, array $remote): Payment
    {
        return Payment::updateOrCreate(
            ['gateway_transaction_id' => $transactionId],
            [
                'order_id' => $order->id,
                'gateway' => 'tamara',
                'type' => $type,
                'amount' => (float) $order->total,
                'currency' => $this->currency(),
                'status' => $status,
                'raw' => $remote,
            ]
        );
    }

    private function buildCheckoutPayload(Order $order): array
    {
        [$firstName, $lastName] = $this->splitName($order->customer_name);
        $address = is_array($order->shipping_address) ? $order->shipping_address : [];
        $line1 = trim(($address['building'] ?? '').' '.($address['street'] ?? '')) ?: ($address['district'] ?? 'N/A');
        $city = $address['city'] ?? 'Riyadh';
        $country = (string) config('services.tamara.country', 'SA');
        $base = rtrim((string) config('app.url'), '/');

        return [
            'order_reference_id' => $order->order_number,
            'total_amount' => $this->money((float) $order->total),
            'description' => "Order {$order->order_number}",
            'country_code' => $country,
            'payment_type' => 'PAY_BY_INSTALMENTS',
            'instalments' => (int) config('services.tamara.instalments', 3),
            'locale' => app()->getLocale() === 'ar' ? 'ar_SA' : 'en_US',
            'items' => $order->items->map(fn ($item) => [
                'reference_id' => (string) ($item->product_id ?? $item->id),
                'type' => 'physical',
                'name' => $item->product_name_ar,
                'sku' => $item->smacc_sku ?: ($item->sku ?: ('SKU-'.($item->product_id ?? $item->id))),
                'quantity' => $item->quantity,
                'unit_price' => $this->money((float) $item->unit_price),
                'total_amount' => $this->money((float) $item->line_total),
                'tax_amount' => $this->money(0),
                'discount_amount' => $this->money(0),
            ])->values()->all(),
            'consumer' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'phone_number' => $order->customer_phone ?: '500000000',
                'email' => $order->customer_email ?: ('customer+'.$order->id.'@retab.com.sa'),
            ],
            'shipping_address' => [
                'first_name' => $firstName,
                'last_name' => $lastName,
                'line1' => $line1,
                'city' => $city,
                'country_code' => $country,
                'phone_number' => $order->customer_phone ?: '500000000',
            ],
            'tax_amount' => $this->money(0), // pricing is VAT-inclusive; no separate tax line yet
            'shipping_amount' => $this->money((float) ($order->shipping_fee ?? 0)),
            'discount' => ((float) $order->discount_total) > 0 ? [
                'name' => $order->coupon?->code ?? 'Discount',
                'amount' => $this->money((float) $order->discount_total),
            ] : null,
            'merchant_url' => [
                'success' => $base.'/checkout/result?status=success',
                'failure' => $base.'/checkout/result?status=failure',
                'cancel' => $base.'/checkout/result?status=cancel',
                'notification' => route('webhooks.tamara'),
            ],
        ];
    }

    private function money(float $amount): array
    {
        return [
            'amount' => round($amount, 2),
            'currency' => $this->currency(),
        ];
    }

    private function expectedMinorAmount(Order $order): int
    {
        return (int) round(((float) $order->total) * 100);
    }

    private function remoteMinorAmount(array $remote): int
    {
        return (int) round(((float) ($remote['total_amount']['amount'] ?? 0)) * 100);
    }

    private function currency(): string
    {
        return strtoupper((string) config('services.tamara.currency', 'SAR'));
    }

    /** @return array{0:string,1:string} */
    private function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = $parts[0] ?? 'Customer';
        $last = count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : $first;

        return [$first, $last];
    }
}
