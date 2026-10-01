<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Log;

/** Development driver: writes messages to storage/logs instead of sending them. */
class LogSmsGateway implements SmsGateway
{
    public function send(string $to, string $message): void
    {
        Log::info("[SMS to {$to}] {$message}");
    }

    public function name(): string
    {
        return 'log';
    }
}
