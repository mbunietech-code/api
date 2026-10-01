<?php

namespace App\Services;

use App\Mail\ContributionReceived;
use App\Models\Contribution;
use App\Models\NotificationLog;
use App\Services\Sms\SmsGateway;
use App\Support\Months;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Tells a teacher (SMS and/or email) that their contribution was recorded. */
class ContributionNotifier
{
    public function __construct(
        private AppSettings $settings,
        private ContributionLedger $ledger,
        private SmsGateway $sms,
    ) {}

    public function message(Contribution $contribution): string
    {
        $teacher = $contribution->teacher;
        $yearTotal = (int) $teacher->contributions()->where('year', $contribution->year)->sum('amount');

        return strtr($this->settings->get('message_template'), [
            '{jina}' => mb_convert_case($teacher->full_name, MB_CASE_TITLE),
            '{kiasi}' => Months::money($contribution->amount),
            '{mwezi}' => Months::LONG[$contribution->month],
            '{mwaka}' => (string) $contribution->year,
            '{tarehe}' => ($contribution->paid_at ?? $contribution->created_at)->format('d/m/Y'),
            '{kumbukumbu}' => $contribution->reference,
            '{jumla}' => Months::money($yearTotal),
            '{shule}' => $this->settings->get('school_name'),
        ]);
    }

    /** @return array<int, NotificationLog> */
    public function send(Contribution $contribution): array
    {
        $contribution->loadMissing('teacher');
        $teacher = $contribution->teacher;
        $message = $this->message($contribution);
        $logs = [];

        if ($this->settings->get('sms_enabled')) {
            $phone = self::normalizePhone($teacher->phone);
            $logs[] = $this->attempt($contribution, 'sms', $phone, $message, function () use ($phone, $message) {
                $this->sms->send($phone, $message);
            });
        }

        if ($this->settings->get('email_enabled')) {
            $email = $teacher->email;
            $logs[] = $this->attempt($contribution, 'email', $email, $message, function () use ($email, $contribution, $message) {
                Mail::to($email)->send(new ContributionReceived($contribution, $message, $this->settings->get('school_name')));
            });
        }

        return $logs;
    }

    private function attempt(Contribution $contribution, string $channel, ?string $recipient, string $message, callable $deliver): NotificationLog
    {
        $status = 'sent';
        $error = null;

        if (! $recipient) {
            $status = 'skipped';
            $error = $channel === 'sms' ? 'Mwalimu hana namba ya simu.' : 'Mwalimu hana barua pepe.';
        } else {
            try {
                $deliver();
                // The log/array drivers only write to storage/logs: nothing reaches the teacher.
                if ($testMode = self::testModeReason($channel, $this->sms)) {
                    $status = 'skipped';
                    $error = $testMode;
                }
            } catch (Throwable $e) {
                $status = 'failed';
                $error = mb_substr($e->getMessage(), 0, 500);
            }
        }

        return NotificationLog::create([
            'contribution_id' => $contribution->id,
            'teacher_id' => $contribution->teacher_id,
            'channel' => $channel,
            'recipient' => $recipient,
            'message' => $message,
            'status' => $status,
            'error' => $error,
        ]);
    }

    /** Why a channel is not really delivering (development drivers), or null. */
    public static function testModeReason(string $channel, SmsGateway $sms): ?string
    {
        if ($channel === 'email' && in_array(config('mail.default'), ['log', 'array'], true)) {
            return 'Hali ya majaribio: MAIL_MAILER='.config('mail.default').' - barua imeandikwa kwenye kumbukumbu, haikutumwa.';
        }
        if ($channel === 'sms' && $sms->name() === 'log') {
            return 'Hali ya majaribio: SMS_DRIVER=log - ujumbe umeandikwa kwenye kumbukumbu, haukutumwa.';
        }

        return null;
    }

    /** Converts local Tanzanian numbers (07xx / 06xx / +255) to 255XXXXXXXXX. */
    public static function normalizePhone(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone);
        if ($digits === '') {
            return null;
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 10) {
            return '255'.substr($digits, 1);
        }
        if (strlen($digits) === 9) {
            return '255'.$digits;
        }

        return $digits;
    }
}
