<?php

namespace App\Jobs;

use App\Services\SmsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Send one order-related SMS on the queue.
 *
 * Order notifications go through the phone gateway, which is a network call — on
 * Laravel Cloud (relay mode) it must not block the admin's status change or the
 * customer's checkout. So the observer / controller only DISPATCH; a worker does
 * the send. Retried a few times so a phone that is briefly offline or out of
 * signal does not silently drop the message.
 *
 * OTP is deliberately NOT routed through here — that send stays synchronous so
 * the user gets immediate "sent / failed" feedback while they wait for the code.
 */
class SendOrderSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public array $backoff = [10, 30, 60]; // seconds between attempts

    public function __construct(
        public ?int $orderId,
        public string $phone,
        public string $message,
    ) {}

    public function handle(SmsService $sms): void
    {
        // SmsService logs the outcome to sms_logs and returns false on failure;
        // throw so the queue retries rather than swallowing a dropped message.
        if (! $sms->send($this->orderId, $this->phone, $this->message)) {
            throw new \RuntimeException("SMS send failed for order {$this->orderId}");
        }
    }
}
