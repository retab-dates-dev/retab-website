<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @mixin IdeHelperOrderActivity
 */
class OrderActivity extends Model
{
    public const UPDATED_AT = null; // append-only log: created_at only

    protected $fillable = [
        'order_id',
        'type',
        'from_status',
        'to_status',
        'user_id',
        'note',
        'meta',
    ];

    protected $casts = [
        'meta' => 'array',
        'created_at' => 'datetime',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Record a status transition on the order's audit trail.
     */
    public static function logStatusChange(Order $order, ?string $from, ?string $to, ?int $userId = null): self
    {
        return static::create([
            'order_id' => $order->id,
            'type' => 'status_change',
            'from_status' => $from,
            'to_status' => $to,
            'user_id' => $userId,
        ]);
    }

    /**
     * Record a tracking-number / carrier update.
     *
     * `$cost` is what the carrier charges the STORE, not the flat fee the
     * customer paid. Kept per entry as well as on the order so a cancelled and
     * re-shipped order still shows what each attempt cost — the order column
     * only ever holds the current shipment.
     */
    public static function logTrackingUpdate(
        Order $order,
        string $trackingNumber,
        ?string $carrier,
        ?int $userId = null,
        float|string|null $cost = null,
        ?string $currency = null,
    ): self {
        return static::create([
            'order_id' => $order->id,
            'type' => 'tracking',
            'user_id' => $userId,
            'meta' => array_filter([
                'tracking_number' => $trackingNumber,
                'carrier' => $carrier,
                'cost' => $cost === null ? null : (float) $cost,
                'currency' => $currency,
            ], fn ($value) => $value !== null),
        ]);
    }

    /**
     * Record that a shipment was recalled from the carrier.
     *
     * Its own type rather than a plain status change: "shipped → confirmed" is
     * the only way an order moves backwards, so a bare status entry technically
     * implies a recall, but nothing on it says WHICH carrier and tracking number
     * were cancelled — which is exactly what someone auditing a double carrier
     * charge needs. from/to are still populated so the status timeline stays
     * continuous.
     *
     * @param  array{tracking_number: ?string, carrier: ?string, cost: float|string|null}  $recalled
     */
    public static function logShipmentCancelled(Order $order, ?string $fromStatus, array $recalled, ?int $userId = null): self
    {
        return static::create([
            'order_id' => $order->id,
            'type' => 'shipment_cancelled',
            'from_status' => $fromStatus,
            'to_status' => $order->status->value,
            'user_id' => $userId,
            'meta' => array_filter([
                'tracking_number' => $recalled['tracking_number'] ?? null,
                'carrier' => $recalled['carrier'] ?? null,
                'cost' => isset($recalled['cost']) ? (float) $recalled['cost'] : null,
            ], fn ($value) => $value !== null),
        ]);
    }

    /**
     * Record that money actually arrived (card captured at checkout).
     *
     * Its own type rather than a bare status change, for the same reason a
     * shipment has one: "pending_payment → awaiting_confirmation" says the order
     * moved on, but not that it moved on BECAUSE a payment settled, nor how much,
     * nor through which gateway. The `payments` ledger stays the authoritative
     * record; this is the line that makes the ORDER's own timeline readable.
     *
     * from/to are populated so the status timeline stays continuous.
     */
    public static function logPaymentReceived(
        Order $order,
        ?string $fromStatus,
        string $gateway,
        float|string|null $amount = null,
        ?string $currency = null,
        ?string $transactionId = null,
        ?string $method = null,
    ): self {
        return static::logPaymentEvent($order, 'payment_received', $fromStatus, $gateway, $amount, $currency, $transactionId, $method);
    }

    /**
     * Record that a BNPL authorization was placed.
     *
     * 🔑 Deliberately a DIFFERENT type from logPaymentReceived. Tamara holds the
     * funds and nothing is captured until an admin confirms, so a hold that can
     * still lapse is not money in the account. Collapsing the two would make the
     * timeline claim a payment that has not happened — and `payment_lapsed`
     * exists precisely because these holds do expire.
     */
    public static function logPaymentAuthorized(
        Order $order,
        ?string $fromStatus,
        string $gateway,
        float|string|null $amount = null,
        ?string $currency = null,
        ?string $transactionId = null,
    ): self {
        return static::logPaymentEvent($order, 'payment_authorized', $fromStatus, $gateway, $amount, $currency, $transactionId, null);
    }

    /**
     * Shared shape for the two payment entries above.
     *
     * ⚠️ The amount key is `amount`, never `cost`: the order-detail timeline
     * renders `meta.cost` as what a CARRIER charged the store, so reusing it
     * here would label a customer payment as a shipping cost.
     */
    private static function logPaymentEvent(
        Order $order,
        string $type,
        ?string $fromStatus,
        string $gateway,
        float|string|null $amount,
        ?string $currency,
        ?string $transactionId,
        ?string $method,
    ): self {
        return static::create([
            'order_id' => $order->id,
            'type' => $type,
            'from_status' => $fromStatus,
            'to_status' => $order->status?->value,
            // No user_id: a payment is settled by the customer and the gateway,
            // never by a member of staff.
            'meta' => array_filter([
                'gateway' => $gateway,
                'amount' => $amount === null ? null : (float) $amount,
                'currency' => $currency,
                'transaction_id' => $transactionId,
                'method' => $method,
            ], fn ($value) => $value !== null),
        ]);
    }
}
