<?php

namespace App\Services;

use App\Models\SmsLog;
use Vonage\Client;
use Vonage\Client\Credentials\Basic;
use Vonage\SMS\Message\SMS;

class SmsService
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client(
            new Basic(
                config('services.vonage.key'),
                config('services.vonage.secret')
            )
        );
    }

    public function send(int $orderId, string $phoneNumber, string $message): bool
    {
        try {
            $this->client->sms()->send(
                new SMS($phoneNumber, config('services.vonage.sms_from'), $message)
            );

            SmsLog::create([
                'order_id'        => $orderId,
                'phone_number'    => $phoneNumber,
                'message_content' => $message,
                'sms_status'      => 'sent',
                'sent_at'         => now(),
            ]);

            return true;

        } catch (\Exception $e) {
            SmsLog::create([
                'order_id'        => $orderId,
                'phone_number'    => $phoneNumber,
                'message_content' => $message,
                'sms_status'      => 'failed',
                'sent_at'         => null,
            ]);

            return false;
        }
    }

    public static function orderPlacedMessage(string $customerName, int $orderId, float $total): string
    {
        return "Hi {$customerName}! Your NCM Paint Center order #{$orderId} has been placed successfully. Total: ₱{$total}. We'll notify you once it's processed.";
    }

    public static function orderStatusMessage(string $customerName, int $orderId, string $status): string
    {
        return "Hi {$customerName}! Your NCM Paint Center order #{$orderId} status has been updated to: {$status}. Thank you!";
    }
}