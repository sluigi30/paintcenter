<?php

namespace App\Services;

/**
 * The outcome of trying to get an invitation to somebody.
 *
 * Exists because "we saved an invite" and "a human will receive it" are
 * different facts, and the panel used to report the first while claiming the
 * second. Three outcomes matter and they are not two:
 *
 *   - sent      — a real transport accepted it
 *   - failed    — the transport threw; nobody got anything
 *   - notMailed — there IS no real transport (MAIL_MAILER=log), so it went to
 *                 a log file. Technically no error, practically the same as a
 *                 failure: nobody received an email.
 *
 * The URL is carried either way, because in the two bad cases it is the only
 * means left of getting the person in.
 */
final readonly class InviteDelivery
{
    private function __construct(
        public string $url,
        public bool $delivered,
        public bool $mailConfigured,
        public ?string $error = null,
    ) {}

    public static function sent(string $url): self
    {
        return new self($url, delivered: true, mailConfigured: true);
    }

    /** Handed to the log mailer — no error, but no recipient either. */
    public static function notMailed(string $url): self
    {
        return new self($url, delivered: false, mailConfigured: false);
    }

    public static function failed(string $url, string $error): self
    {
        return new self($url, delivered: false, mailConfigured: true, error: $error);
    }

    /**
     * Did anything actually reach a person? The one question the panel has to
     * answer honestly, and the reason this class exists.
     */
    public function reachedSomeone(): bool
    {
        return $this->delivered;
    }
}
