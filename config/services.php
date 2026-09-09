<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // SMS is sent through an Android phone + SIM running an HTTP gateway app
    // (see app/Services/SmsService). Leave `url` blank to run without sending —
    // in local dev, OTP codes are written to the log and returned as dev_code.
    'sms_gateway' => [
        'url'  => env('SMS_GATEWAY_URL'),
        'user' => env('SMS_GATEWAY_USER'),
        'pass' => env('SMS_GATEWAY_PASS'),
    ],

    // Phone verification on registration. `enabled` is the on/off switch: keep
    // it off until the SMS gateway phone is live, then flip it to require OTP.
    'otp' => [
        'enabled'      => env('OTP_ENABLED', false),
        'ttl'          => 5, // minutes a code stays valid
        'max_attempts' => 5, // wrong tries before a new code is required
    ],

];
