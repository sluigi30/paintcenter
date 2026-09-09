<?php

namespace App\Services;

use App\Models\Order;
use App\Models\SmsLog;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class SmsService
{
    /**
     * Send an SMS through the Android phone gateway.
     *
     * Transport is deliberately swappable and lives ONLY here. The app talks to
     * a small HTTP gateway — an Android phone + SIM running in local (same-LAN)
     * or cloud/relay mode — not a branded SMS provider. Providers like Semaphore
     * require a sender-name registration this project cannot obtain, so the
     * "sender" is simply the SIM's own mobile number.
     *
     * @param int|null $orderId Null for messages with no order (e.g. OTP).
     */
    public function send(?int $orderId, string $phoneNumber, string $message): bool
    {
        $to  = self::toE164($phoneNumber);
        $url = config('services.sms_gateway.url');

        // No gateway wired yet. In local dev, log the message (so OTP codes are
        // readable without a real SIM) and count it as sent so the flow can be
        // exercised end to end; anywhere else, record the failure exactly like a
        // real send error would.
        if (blank($url)) {
            if (app()->environment('local')) {
                Log::info("[SMS dev] to {$to}: {$message}");

                return $this->log($orderId, $to, $message, 'sent');
            }

            return $this->log($orderId, $to, $message, 'failed');
        }

        try {
            Http::withBasicAuth(
                config('services.sms_gateway.user'),
                config('services.sms_gateway.pass'),
            )
                ->timeout(15)
                // SMSGate, Basic auth, this body shape. SMS_GATEWAY_URL is the
                // FULL endpoint: local server uses http://<ip>:8080/message; the
                // cloud 3rd-party API uses https://api.sms-gate.app/3rdparty/v1/messages.
                ->post($url, [
                    'textMessage'  => ['text' => $message],
                    'phoneNumbers' => [$to],
                ])
                ->throw();

            return $this->log($orderId, $to, $message, 'sent');
        } catch (\Throwable $e) {
            Log::warning("[SMS] send failed to {$to}: {$e->getMessage()}");

            return $this->log($orderId, $to, $message, 'failed');
        }
    }

    /**
     * Send a one-time password. OTP is an ordinary SMS on this transport — there
     * is no dedicated OTP channel to route it through, unlike a paid gateway.
     */
    public function sendOtp(string $phoneNumber, string $code): bool
    {
        $ttl = config('services.otp.ttl', 5);

        return $this->send(
            null,
            $phoneNumber,
            "Your NCM Paint Center verification code is {$code}. "
            . "It expires in {$ttl} minutes. Do not share this code with anyone.",
        );
    }

    private function log(?int $orderId, string $phone, string $message, string $status): bool
    {
        SmsLog::create([
            'order_id'        => $orderId,
            'phone_number'    => $phone,
            'message_content' => $message,
            'sms_status'      => $status,
            'sent_at'         => $status === 'sent' ? now() : null,
        ]);

        return $status === 'sent';
    }

    // ── Message builders ──────────────────────────────────────────────

    public static function orderPlacedMessage(string $firstName, string $placedAt, float $total): string
    {
        return "Hi {$firstName}! Your NCM Paint Center order placed on {$placedAt} "
            . 'has been received. Total: PHP ' . number_format($total, 2)
            . ". We'll text you as it progresses.";
    }

    /**
     * Customer-facing wording per status. Orders are identified by their placed
     * DATE, never their id — an id is a shared auto-increment, so "#147" on a
     * first order reads as 146 missing ones. This mirrors STATUS_META in the
     * mobile app's constants/orders.js; keep the two vocabularies in step.
     */
    public static function orderStatusMessage(Order $order): string
    {
        $name     = $order->user?->first_name ?? 'there';
        $placedAt = ($order->order_date ?? $order->created_at)?->format('j M Y') ?? 'your recent order';

        $line = match ($order->status) {
            'processing'       => 'is now being prepared at the store.',
            'shipped'          => 'is out for delivery and on its way to you.',
            'ready_for_pickup' => 'is packed and ready for pickup at the store.',
            'completed'        => 'is complete. Thank you for shopping with us!',
            'cancelled'        => 'has been cancelled'
                . ($order->cancellation_reason ? " ({$order->cancellation_reason})." : '.'),
            default            => "has an update: {$order->status}.",
        };

        return "Hi {$name}! Your NCM Paint Center order from {$placedAt} {$line}";
    }

    // ── Phone helpers ─────────────────────────────────────────────────

    /**
     * Canonical local form 09XXXXXXXXX — the key OTP rows are stored and looked
     * up by, so send / verify / register all agree on one string regardless of
     * whether the user typed +63, 63 or 0.
     */
    public static function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/[^0-9]/', '', $phone);

        if (str_starts_with($digits, '63')) {
            $digits = '0' . substr($digits, 2);
        } elseif (! str_starts_with($digits, '0') && strlen($digits) === 10) {
            $digits = '0' . $digits; // bare 9XXXXXXXXX
        }

        return $digits;
    }

    /** E.164 (+639XXXXXXXXX) — what the gateway hands to the network. */
    public static function toE164(string $phone): string
    {
        $local = self::normalizePhone($phone);

        return str_starts_with($local, '0') ? '+63' . substr($local, 1) : $local;
    }
}
