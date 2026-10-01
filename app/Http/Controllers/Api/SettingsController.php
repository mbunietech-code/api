<?php

namespace App\Http\Controllers\Api;

use App\Models\NotificationLog;
use App\Services\AppSettings;
use App\Services\ContributionNotifier;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class SettingsController extends Controller
{
    public function __construct(private AppSettings $settings) {}

    public function show()
    {
        return $this->payload();
    }

    public function update(Request $request)
    {
        abort_unless($request->user()->isAdmin(), 403, 'Huna ruhusa ya kubadilisha mipangilio.');

        $data = $request->validate([
            'school_name' => ['sometimes', 'string', 'max:150'],
            'system_name' => ['sometimes', 'string', 'max:150'],
            'active_year' => ['sometimes', 'integer', 'between:2000,2100'],
            'monthly_amount' => ['sometimes', 'integer', 'min:1'],
            'reference_prefix' => ['sometimes', 'string', 'max:10', 'regex:/^[A-Za-z0-9]+$/'],
            'sms_enabled' => ['sometimes', 'boolean'],
            'email_enabled' => ['sometimes', 'boolean'],
            'notify_on_update' => ['sometimes', 'boolean'],
            'message_template' => ['sometimes', 'string', 'max:480'],
        ]);

        $this->settings->update($data);

        return $this->payload();
    }

    /** Sends a test SMS / email so the admin can verify the gateway configuration. */
    public function test(Request $request, \App\Services\Sms\SmsGateway $sms)
    {
        $data = $request->validate([
            'channel' => ['required', 'in:sms,email'],
            'recipient' => ['required', 'string', 'max:150'],
        ]);
        $message = 'Ujumbe wa majaribio kutoka '.$this->settings->get('system_name').' - '.$this->settings->get('school_name').'.';

        try {
            if ($data['channel'] === 'sms') {
                $sms->send(ContributionNotifier::normalizePhone($data['recipient']), $message);
            } else {
                Mail::raw($message, fn ($m) => $m->to($data['recipient'])->subject('Ujumbe wa majaribio'));
            }
        } catch (Throwable $e) {
            return response()->json(['message' => 'Imeshindikana: '.$e->getMessage()], 422);
        }

        return ['message' => 'Ujumbe wa majaribio umetumwa.'];
    }

    public function notifications(Request $request)
    {
        return NotificationLog::with('teacher:id,full_name')
            ->latest()
            ->limit(min(200, (int) $request->query('limit', 50)))
            ->get()
            ->map(fn ($log) => [
                'id' => $log->id,
                'teacher_name' => $log->teacher?->full_name,
                'channel' => $log->channel,
                'recipient' => $log->recipient,
                'message' => $log->message,
                'status' => $log->status,
                'error' => $log->error,
                'created_at' => $log->created_at->toIso8601String(),
            ]);
    }

    private function payload(): array
    {
        return $this->settings->all() + [
            'sms_driver' => config('services.sms.driver'),
            'mail_driver' => config('mail.default'),
            'mail_from' => config('mail.from.address'),
        ];
    }
}
