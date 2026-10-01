<?php

namespace App\Services\Sms;

interface SmsGateway
{
    /**
     * Send a single SMS. Throws on failure so the caller can log the error.
     *
     * @param  string  $to  Phone number in international format without "+", e.g. 255712345678.
     */
    public function send(string $to, string $message): void;

    public function name(): string;
}
