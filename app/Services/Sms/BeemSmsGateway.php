<?php

namespace App\Services\Sms;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/** Beem Africa (https://beem.africa) bulk SMS API. */
class BeemSmsGateway implements SmsGateway
{
    public function __construct(
        private string $apiKey,
        private string $secretKey,
        private string $senderId,
    ) {}

    public function send(string $to, string $message): void
    {
        $response = Http::withBasicAuth($this->apiKey, $this->secretKey)
            ->acceptJson()
            ->timeout(20)
            ->post('https://apisms.beem.africa/v1/send', [
                'source_addr' => $this->senderId,
                'schedule_time' => '',
                'encoding' => 0,
                'message' => $message,
                'recipients' => [['recipient_id' => 1, 'dest_addr' => $to]],
            ]);

        if (! $response->successful() || $response->json('successful') === false) {
            throw new RuntimeException('Beem: '.($response->json('message') ?? $response->body()));
        }
    }

    public function name(): string
    {
        return 'beem';
    }
}
