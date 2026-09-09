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
 * Order notifications go through the phone gateway (a network call). Kept as a
 * job so the send path is uniform whether it runs inline (QUEUE_CONNECTION=sync,
 * the current setup) or later on a background worker.
 *
 * It never throws. SmsService already records the outcome in sms_logs, and a
 * failed send must NOT break the request that triggered it — with `sync` that
 * request is the admin's "save status" click, or a customer's checkout. A
 * dropped order text is non-critical: the in-app message thread and order
 * polling also keep the customer informed.
 *
 * OTP is deliberately NOT routed through here — that send stays synchronous so
 * the user gets immediate "sent / failed" feedback while they wait for the code.
 */
class SendOrderSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public ?int $orderId,
        public string $phone,
        public string $message,
    ) {}

    public function handle(SmsService $sms): void
    {
        $sms->send($this->orderId, $this->phone, $this->message);
    }
}
