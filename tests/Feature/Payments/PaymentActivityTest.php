<?php

namespace Tests\Feature\Payments;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\OrderActivity;
use App\Services\Payments\NormalizedPayment;
use App\Services\Payments\PaymentGateway;
use App\Services\Payments\PaymentService;
use App\Services\Payments\Tamara\TamaraClient;
use App\Services\Payments\Tamara\TamaraService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The order timeline records failures (payment_lapsed) and admin actions
 * (payment_link_sent), but for months recorded nothing when money actually
 * ARRIVED — so an admin reading an order saw its status move with no line
 * explaining why. These pin the entries that close that hole.
 */
class PaymentActivityTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $overrides = []): Order
    {
        return Order::create(array_merge([
            'order_number' => 'RTB-ACT-1',
            'customer_name' => 'Test Customer',
            'customer_phone' => '+966500000000',
            'shipping_address' => ['country' => 'SA', 'city' => 'Riyadh'],
            'status' => OrderStatus::PendingPayment,
            'payment_status' => PaymentStatus::Pending,
            'gateway_reference' => 'inv_1',
            'subtotal' => 100,
            'total' => 125,
        ], $overrides));
    }

    private function cardService(NormalizedPayment $payment): PaymentService
    {
        $gateway = new class($payment) implements PaymentGateway
        {
            public function __construct(private NormalizedPayment $payment) {}

            public function createInvoice(Order $order): array
            {
                return ['url' => 'https://pay.test/inv_1', 'invoice_id' => 'inv_1', 'raw' => []];
            }

            public function fetchPayment(string $paymentId): NormalizedPayment
            {
                return $this->payment;
            }

            public function fetchInvoice(string $invoiceId): array
            {
                return ['status' => 'paid', 'payments' => [$this->payment], 'raw' => []];
            }

            public function verifyWebhookToken(?string $token): bool
            {
                return $token === 'secret';
            }

            public function refundPayment(string $paymentId, int $amount): NormalizedPayment
            {
                return $this->payment;
            }
        };

        return new PaymentService($gateway);
    }

    private function tamaraService(string $remoteStatus = 'approved'): TamaraService
    {
        $client = new class('t', 'n', 'https://x') extends TamaraClient
        {
            public string $remoteStatus = 'approved';

            public function getOrder(string $orderId): array
            {
                return ['status' => $this->remoteStatus, 'total_amount' => ['amount' => 125.00, 'currency' => 'SAR']];
            }

            public function authorise(string $orderId): array
            {
                return [];
            }

            public function capture(string $orderId, array $payload): array
            {
                return ['capture_id' => 'cap_1'];
            }

            public function cancel(string $orderId, array $payload): array
            {
                return [];
            }
        };
        $client->remoteStatus = $remoteStatus;

        return new TamaraService($client);
    }

    public function test_a_captured_card_payment_is_recorded_on_the_order_timeline(): void
    {
        $order = $this->makeOrder();

        $this->cardService(new NormalizedPayment(
            id: 'pay_1', status: 'paid', amount: 12500, currency: 'SAR',
            sourceCompany: 'visa', invoiceId: 'inv_1', orderId: $order->id,
        ))->confirmFromGateway('pay_1');

        $activity = OrderActivity::where('order_id', $order->id)->where('type', 'payment_received')->sole();

        // The transition the payment caused, so the timeline stays continuous.
        $this->assertSame(OrderStatus::PendingPayment->value, $activity->from_status);
        $this->assertSame(OrderStatus::AwaitingConfirmation->value, $activity->to_status);

        $this->assertSame('moyasar', $activity->meta['gateway']);
        // ⚠️ assertEquals, not assertSame: `meta` is a JSON column, and PHP
        // serialises a whole float as a JSON int, so 125.0 round-trips as 125.
        $this->assertEquals(125.0, $activity->meta['amount']);
        $this->assertSame('SAR', $activity->meta['currency']);
        // The gateway's own id, so a bank line can be traced back to this order.
        $this->assertSame('pay_1', $activity->meta['transaction_id']);
        $this->assertSame('visa', $activity->meta['method']);

        // A payment is settled by the customer and the gateway, never by staff.
        $this->assertNull($activity->user_id);
    }

    public function test_a_repeated_webhook_delivery_does_not_log_the_payment_twice(): void
    {
        $order = $this->makeOrder();
        $service = $this->cardService(new NormalizedPayment(
            id: 'pay_1', status: 'paid', amount: 12500, currency: 'SAR',
            invoiceId: 'inv_1', orderId: $order->id,
        ));

        $service->confirmFromGateway('pay_1');
        $service->confirmFromGateway('pay_1');
        $service->confirmFromGateway('pay_1');

        $this->assertSame(1, OrderActivity::where('order_id', $order->id)->where('type', 'payment_received')->count());
    }

    public function test_a_failed_payment_records_no_payment_entry(): void
    {
        $order = $this->makeOrder();

        $this->cardService(new NormalizedPayment(
            id: 'pay_1', status: 'failed', amount: 12500, currency: 'SAR',
            invoiceId: 'inv_1', orderId: $order->id,
        ))->confirmFromGateway('pay_1');

        $this->assertSame(0, OrderActivity::where('order_id', $order->id)->where('type', 'payment_received')->count());
    }

    public function test_a_tamara_authorization_is_recorded_as_a_hold_not_a_payment(): void
    {
        $order = $this->makeOrder(['order_number' => 'RTB-ACT-2', 'payment_gateway' => 'tamara', 'gateway_reference' => 'tamara_1']);

        $this->tamaraService('approved')->confirm('tamara_1');

        // 🔑 Its own type. Tamara holds the funds and captures only on admin
        // confirmation, so labelling it "received" would claim money we do not have.
        $this->assertSame(0, OrderActivity::where('order_id', $order->id)->where('type', 'payment_received')->count());

        $activity = OrderActivity::where('order_id', $order->id)->where('type', 'payment_authorized')->sole();
        $this->assertSame('tamara', $activity->meta['gateway']);
        $this->assertSame(OrderStatus::PendingPayment->value, $activity->from_status);
        $this->assertSame(OrderStatus::AwaitingConfirmation->value, $activity->to_status);
    }

    public function test_a_repeated_tamara_webhook_does_not_log_the_authorization_twice(): void
    {
        $order = $this->makeOrder(['order_number' => 'RTB-ACT-3', 'payment_gateway' => 'tamara', 'gateway_reference' => 'tamara_1']);
        $service = $this->tamaraService('approved');

        $service->confirm('tamara_1');
        $service->confirm('tamara_1');

        $this->assertSame(1, OrderActivity::where('order_id', $order->id)->where('type', 'payment_authorized')->count());
    }

    /**
     * 🔴 The renderer's switch ends in a bare `a.type` fallback, so a type the
     * server writes but the client does not handle renders as raw snake_case
     * instead of failing — which is exactly how `payment_lapsed` and
     * `payment_link_sent` shipped unrendered for months. Nothing else in the
     * build catches it: it is a successful render of the wrong string, invisible
     * to tsc, to PHPUnit and to the E2E suite.
     *
     * Add a type here when you add one on the server, and the failure tells you
     * to teach the renderer about it.
     */
    public function test_every_activity_type_the_server_writes_is_handled_by_the_renderer(): void
    {
        $types = [
            'status_change',
            'tracking',
            'shipment_cancelled',
            'payment_received',
            'payment_authorized',
            'payment_lapsed',
            'payment_link_sent',
        ];

        $renderer = file_get_contents(resource_path('js/components/admin/order-detail-view.tsx'));

        foreach ($types as $type) {
            $this->assertStringContainsString(
                "case '{$type}':",
                $renderer,
                "The order timeline renderer has no branch for '{$type}', so it will render as raw snake_case."
            );
        }
    }
}
