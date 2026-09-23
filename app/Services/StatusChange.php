<?php

namespace App\Services;

use App\Models\Order;

/**
 * The outcome of trying to move an order one step along its flow.
 *
 * Three outcomes, and the caller has to tell them apart: an order that moved,
 * an order that had nowhere left to go, and an order somebody else moved first.
 * The last one is not an error — it is two people doing the same job — and it
 * needs different words from "this order is finished".
 *
 * Same shape as InviteDelivery, for the same reason: a bare bool would collapse
 * two different situations into one message.
 */
final readonly class StatusChange
{
    private function __construct(
        public string $outcome,
        public ?string $from = null,
        public ?string $to = null,
    ) {}

    public static function moved(string $from, string $to): self
    {
        return new self('moved', $from, $to);
    }

    /** Somebody else moved it between the button rendering and the press. */
    public static function conflict(): self
    {
        return new self('conflict');
    }

    /** End of the flow, or a cancelled order, or a status its type never uses. */
    public static function noStep(): self
    {
        return new self('no_step');
    }

    public function succeeded(): bool
    {
        return $this->outcome === 'moved';
    }

    public function isConflict(): bool
    {
        return $this->outcome === 'conflict';
    }

    /** "Out for Delivery" rather than "shipped", for anything user-facing. */
    public function label(): string
    {
        return Order::statusLabel($this->to);
    }
}
