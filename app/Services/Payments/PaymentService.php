<?php

namespace App\Services\Payments;

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
 * Orchestrates the order <-> Moyasar (card/mada/Apple Pay/STC Pay) lifecycle.
 *
 * The golden rule lives here: an order is only ever marked paid after we
 * RE-FETCH the payment from the gateway and confirm status + amount + currency
 * ourselves. The browser redirect and the webhook body are treated as untrusted
 * triggers, never as proof of payment. confirmFromGateway() is idempotent and
 * row-locked, so duplicate webhooks, the success redirect, and the reconciliation
 * sweeper can all fire for the same order without double-charging or
 * double-fulfilling.
 *
 * Retab note: cards capture at checkout, so a verified payment moves the order to
 * `awaiting_confirmation` (NOT a stock deduction — that happens at admin
 * confirmation, because inventory is reconciled from SMACC).
 */
class PaymentService
{
    public function __construct(
        protected PaymentGateway $gateway,
    ) {}

    /**
     * Create a hosted payment session and return the redirect URL. Reuses the
     * existing invoice URL if the customer abandoned and retried.
     */
    public function initiate(Order $order): string
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            throw new \RuntimeException('Order is already paid.');
        }

        if ($order->payment_url && $order->gateway_reference) {
            return $order->payment_url;
        }

        $invoice = $this->gateway->createInvoice($order);

        $order->forceFill([
            'payment_gateway' => 'moyasar',
            'gateway_reference' => $invoice['invoice_id'],
            'payment_url' => $invoice['url'],
        ])->save();

        return $invoice['url'];
    }

    /**
     * Authoritative, idempotent confirmation. Re-fetches the payment, verifies it
     * against the order, and transitions the order exactly once.
     */
    public function confirmFromGateway(string $paymentId): ?Order
    {
        $payment = $this->gateway->fetchPayment($paymentId);

        $order = $this->resolveOrder($payment);
        if (! $order) {
            Log::warning('Payment could not be matched to an order', [
                'payment_id' => $payment->id,
                'invoice_id' => $payment->invoiceId,
                'order_id_meta' => $payment->orderId,
            ]);

            return null;
        }

        return DB::transaction(function () use ($order, $payment) {
            // Lock the row so concurrent webhook + redirect + sweeper calls serialize.
            $order = Order::whereKey($order->id)->lockForUpdate()->first();

            // Idempotency anchor: this gateway payment already recorded as final.
            $existing = Payment::where('gateway_transaction_id', $payment->id)
                ->whereIn('status', ['succeeded', 'failed'])
                ->first();
            if ($existing) {
                return $order;
            }

            $expected = $this->expectedMinorAmount($order);
            $amountOk = $payment->amount === $expected;
            $currencyOk = strtoupper($payment->currency) === $this->configuredCurrency();

            if ($payment->isPaid() && $amountOk && $currencyOk) {
                $this->recordTransaction($order, PaymentTransactionType::Capture, 'succeeded', $payment);
                $this->markOrderPaid($order, $payment);

                return $order;
            }

            // Paid but amount/currency mismatch = tampering / wrong invoice. Never fulfill.
            if ($payment->isPaid()) {
                Log::error('Paid Moyasar payment failed verification', [
                    'order_id' => $order->id,
                    'payment_id' => $payment->id,
                    'expected_amount' => $expected,
                    'got_amount' => $payment->amount,
                    'expected_currency' => $this->configuredCurrency(),
                    'got_currency' => strtoupper($payment->currency),
                ]);
                $this->recordTransaction($order, PaymentTransactionType::Capture, 'mismatch', $payment);

                return $order;
            }

            if ($payment->isFailed()) {
                $this->recordTransaction($order, PaymentTransactionType::Capture, 'failed', $payment);
                $order->forceFill(['payment_status' => PaymentStatus::Failed])->save();
            }

            return $order;
        });
    }

    /**
     * Re-check an order against the gateway to recover from a missed webhook.
     * Returns true if the order is now paid.
     */
    public function reconcile(Order $order): bool
    {
        if (! $order->gateway_reference) {
            return false;
        }

        $invoice = $this->gateway->fetchInvoice($order->gateway_reference);

        foreach ($invoice['payments'] as $payment) {
            if ($payment->isPaid()) {
                $this->confirmFromGateway($payment->id);

                return $order->fresh()->payment_status === PaymentStatus::Paid;
            }
        }

        return false;
    }

    /**
     * Refund (full or partial) a captured card payment via Moyasar. Records a
     * Refund ledger row and moves payment_status to Refunded/PartiallyRefunded.
     * Amount is in SAR (major units).
     */
    public function refund(Order $order, float $amount): Payment
    {
        $capture = Payment::where('order_id', $order->id)
            ->where('gateway', 'moyasar')
            ->where('type', PaymentTransactionType::Capture->value)
            ->where('status', 'succeeded')
            ->first();

        if (! $capture) {
            throw new \RuntimeException('No captured card payment to refund.');
        }

        $normalized = $this->gateway->refundPayment($capture->gateway_transaction_id, (int) round($amount * 100));

        $refund = Payment::create([
            'order_id' => $order->id,
            'gateway' => 'moyasar',
            'gateway_transaction_id' => $capture->gateway_transaction_id.'-refund-'.now()->format('YmdHis'),
            'type' => PaymentTransactionType::Refund,
            'amount' => round($amount, 2),
            'currency' => $this->configuredCurrency(),
            'status' => 'succeeded',
            'raw' => $normalized->raw,
        ]);

        $this->applyRefundStatus($order);

        return $refund;
    }

    /** Refunded when the refund ledger covers the order total, else partial. */
    private function applyRefundStatus(Order $order): void
    {
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

    private function markOrderPaid(Order $order, ?NormalizedPayment $payment = null): void
    {
        if ($order->payment_status === PaymentStatus::Paid) {
            return;
        }

        // Read BEFORE the write, so the audit line records the transition this
        // payment actually caused rather than the state it left behind.
        $fromStatus = $order->status?->value;

        $order->forceFill([
            'payment_status' => PaymentStatus::Paid,
            'payment_method' => PaymentMethod::Card,
            // Card captured at checkout → hand off to admin for the inventory check.
            'status' => $order->status === OrderStatus::PendingPayment
                ? OrderStatus::AwaitingConfirmation
                : $order->status,
            'paid_at' => now(),
        ])->save();

        // The order's own timeline had no line for money arriving, so an admin
        // reading it saw the status move with nothing explaining why. Sits inside
        // the early return above, so a repeated webhook cannot log it twice.
        OrderActivity::logPaymentReceived(
            $order,
            $fromStatus,
            'moyasar',
            $payment ? $payment->amount / 100 : $order->total,
            $payment?->currency ?? $this->configuredCurrency(),
            $payment?->id,
            $payment?->sourceCompany,
        );

        // The customer's receipt waits for real money. Sending it at checkout would
        // promise an order to anyone who merely reached the hosted card page and
        // then abandoned it. The early return above makes a repeated webhook a
        // no-op, so this can't double-send.
        app(CustomerMailer::class)->orderPlaced($order);
    }

    private function recordTransaction(Order $order, PaymentTransactionType $type, string $status, NormalizedPayment $payment): Payment
    {
        return Payment::updateOrCreate(
            ['gateway_transaction_id' => $payment->id],
            [
                'order_id' => $order->id,
                'gateway' => 'moyasar',
                'type' => $type,
                'amount' => round($payment->amount / 100, 2), // halalas → SAR
                'currency' => $payment->currency,
                'status' => $status,
                'raw' => $payment->raw,
            ]
        );
    }

    private function resolveOrder(NormalizedPayment $payment): ?Order
    {
        if ($payment->orderId) {
            $order = Order::find($payment->orderId);
            if ($order) {
                return $order;
            }
        }

        if ($payment->invoiceId) {
            return Order::where('gateway_reference', $payment->invoiceId)->first();
        }

        return null;
    }

    /** Expected charge amount in minor units (halalas) for verification. */
    private function expectedMinorAmount(Order $order): int
    {
        return (int) round(((float) $order->total) * 100);
    }

    private function configuredCurrency(): string
    {
        return strtoupper((string) config('services.moyasar.currency', 'SAR'));
    }
}
