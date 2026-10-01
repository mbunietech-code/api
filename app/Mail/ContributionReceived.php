<?php

namespace App\Mail;

use App\Models\Contribution;
use App\Support\Months;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class ContributionReceived extends Mailable
{
    use Queueable;

    public function __construct(
        public Contribution $contribution,
        public string $body,
        public string $schoolName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Mchango wa Ustawi wa Jamii - '.Months::LONG[$this->contribution->month].' '.$this->contribution->year,
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.contribution-received');
    }
}
