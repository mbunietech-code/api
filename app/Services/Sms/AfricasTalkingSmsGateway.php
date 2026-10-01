<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Africa's Talking SMS API (https://africastalking.com). */
class AfricasTalkingSmsGateway implements SmsGateway
{
    public function __construct(
        private string $username,
        private string $apiKey,
        private ?string $senderId,
    ) {}

    public function send(string $to, string $message): void
    {
        $host = $this->username === 'sandbox' ? 'api.sandbox.africastalking.com' : 'api.africastalking.com';

        $response = Http::withHeaders(['apiKey' => $this->apiKey])
            ->acceptJson()
            ->asForm()
            ->timeout(20)
            ->post("https://{$host}/version1/messaging", array_filter([
                'username' => $this->username,
                'to' => '+'.$to,
                'message' => $message,
                'from' => $this->senderId,
            ]));

        $status = $response->json('SMSMessageData.Recipients.0.status');
        if (! $response->successful() || ($status && $status !== 'Success')) {
            throw new RuntimeException("Africa's Talking: ".($status ?? $response->body()));
        }
    }

    public function name(): string
    {
        return 'africastalking';
    }
}
