<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendContributionNotifications;
use App\Models\Contribution;
use App\Models\Teacher;
use App\Services\AppSettings;
use App\Services\ContributionLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Offline-first sync for the mobile/desktop app.
 *
 * The app keeps a full copy of the data in a local SQLite database and works
 * without network. When the server is reachable it pushes its pending changes
 * (identified by UUID) and pulls everything that changed on the server since
 * its last pull. Conflict rule: the last change to reach the server wins.
 */
class SyncController extends Controller
{
    public function __construct(private AppSettings $settings, private ContributionLedger $ledger) {}

    public function pull(Request $request)
    {
        $serverTime = now();
        $since = $request->query('since') ? Carbon::parse($request->query('since'))->setTimezone(config('app.timezone'))->subSeconds(2) : null;

        $teachers = Teacher::withTrashed()
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('id')
            ->get()
            ->map(fn (Teacher $t) => self::teacherPayload($t));

        $contributions = Contribution::withTrashed()
            ->with(['teacher', 'recorder'])
            ->when($since, fn ($q) => $q->where('updated_at', '>=', $since))
            ->orderBy('id')
            ->get()
            ->map(fn (Contribution $c) => self::contributionPayload($c));

        return [
            'server_time' => $serverTime->toIso8601String(),
            'full' => $since === null,
            'teachers' => $teachers,
            'contributions' => $contributions,
            'settings' => $this->settings->all() + [
                'sms_driver' => config('services.sms.driver'),
                'mail_driver' => config('mail.default'),
                'mail_from' => config('mail.from.address'),
            ],
        ];
    }

    public function push(Request $request)
    {
        $data = $request->validate([
            'teachers' => ['array'],
            'teachers.*.uuid' => ['required', 'uuid'],
            'teachers.*.full_name' => ['required', 'string', 'max:150'],
            'teachers.*.number' => ['nullable', 'integer'],
            'teachers.*.phone' => ['nullable', 'string', 'max:30'],
            'teachers.*.email' => ['nullable', 'string', 'max:150'],
            'teachers.*.is_active' => ['boolean'],
            'teachers.*.notes' => ['nullable', 'string', 'max:1000'],
            'teachers.*.deleted' => ['boolean'],
            'contributions' => ['array'],
            'contributions.*.uuid' => ['required', 'uuid'],
            'contributions.*.teacher_uuid' => ['required', 'uuid'],
            'contributions.*.year' => ['required', 'integer', 'between:2000,2100'],
            'contributions.*.month' => ['required', 'integer', 'between:1,12'],
            'contributions.*.amount' => ['required', 'integer', 'min:0'],
            'contributions.*.paid_at' => ['nullable', 'date'],
            'contributions.*.reference' => ['nullable', 'string', 'max:60'],
            'contributions.*.notes' => ['nullable', 'string', 'max:1000'],
            'contributions.*.source' => ['nullable', 'in:manual,import'],
            'contributions.*.created_at' => ['nullable', 'date'],
            'contributions.*.deleted' => ['boolean'],
            'contributions.*.notify' => ['boolean'],
        ]);

        $remap = [];
        $teachersAccepted = [];
        $contribAccepted = [];
        $contribErrors = [];
        $ids = [];
        $notify = [];
        $userId = $request->user()->id;

        DB::transaction(function () use ($data, $userId, &$remap, &$teachersAccepted, &$contribAccepted, &$contribErrors, &$ids, &$notify) {
            foreach ($data['teachers'] ?? [] as $t) {
                $model = Teacher::withTrashed()->firstWhere('uuid', $t['uuid']);
                if (! $model) {
                    // Same person added on another device (or before the first sync): merge.
                    $existing = Teacher::withTrashed()->firstWhere('name_key', Teacher::keyFor($t['full_name']));
                    if ($existing) {
                        $remap[$t['uuid']] = $existing->uuid;
                        $existing->fill(array_filter([
                            'phone' => $existing->phone ? null : ($t['phone'] ?? null),
                            'email' => $existing->email ? null : ($t['email'] ?? null),
                        ]));
                        if ($existing->trashed() && empty($t['deleted'])) {
                            $existing->restore();
                        }
                        $existing->save();
                        $teachersAccepted[] = $t['uuid'];

                        continue;
                    }
                    $model = new Teacher(['uuid' => $t['uuid']]);
                }

                if (! empty($t['deleted'])) {
                    if ($model->exists && ! $model->trashed()) {
                        $model->delete();
                    }
                    $teachersAccepted[] = $t['uuid'];

                    continue;
                }

                $model->fill([
                    'full_name' => $t['full_name'],
                    'number' => $t['number'] ?? $model->number ?? ((int) Teacher::withTrashed()->max('number') + 1),
                    'phone' => $t['phone'] ?? null,
                    'email' => $t['email'] ?? null,
                    'is_active' => $t['is_active'] ?? true,
                    'notes' => $t['notes'] ?? null,
                ]);
                if ($model->trashed()) {
                    $model->restore();
                }
                $model->save();
                $teachersAccepted[] = $t['uuid'];
            }

            foreach ($data['contributions'] ?? [] as $c) {
                $teacherUuid = $remap[$c['teacher_uuid']] ?? $c['teacher_uuid'];
                $teacher = Teacher::withTrashed()->firstWhere('uuid', $teacherUuid);
                if (! $teacher) {
                    $contribErrors[$c['uuid']] = 'Mwalimu wa mchango huu hajapatikana kwenye seva.';

                    continue;
                }

                $model = Contribution::withTrashed()->firstWhere('uuid', $c['uuid']);
                $isNew = $model === null;

                if (! empty($c['deleted'])) {
                    if ($model && ! $model->trashed()) {
                        $model->delete();
                    }
                    $contribAccepted[] = $c['uuid'];

                    continue;
                }

                if ($c['amount'] < 1) {
                    $contribErrors[$c['uuid']] = 'Kiasi lazima kiwe zaidi ya sifuri.';

                    continue;
                }

                $model ??= new Contribution(['uuid' => $c['uuid'], 'recorded_by' => $userId]);
                $model->fill([
                    'teacher_id' => $teacher->id,
                    'year' => $c['year'],
                    'month' => $c['month'],
                    'amount' => $c['amount'],
                    'paid_at' => $c['paid_at'] ?? null,
                    'reference' => $c['reference'] ?? $model->reference ?? $this->ledger->nextReference($c['year']),
                    'notes' => $c['notes'] ?? null,
                    'source' => $c['source'] ?? $model->source ?? 'manual',
                ]);
                if ($isNew && ! empty($c['created_at'])) {
                    $model->created_at = Carbon::parse($c['created_at'])->setTimezone(config('app.timezone'));
                }
                if ($model->trashed()) {
                    $model->restore();
                }
                $model->save();

                $contribAccepted[] = $c['uuid'];
                $ids[$c['uuid']] = $model->id;
                if (! empty($c['notify'])) {
                    $notify[] = [$model->id, ! $isNew];
                }
            }
        });

        foreach ($notify as [$id, $force]) {
            SendContributionNotifications::dispatchAfterResponse($id, $force);
        }

        return [
            'server_time' => now()->toIso8601String(),
            'teachers' => ['accepted' => $teachersAccepted, 'remap' => (object) $remap],
            'contributions' => ['accepted' => $contribAccepted, 'errors' => (object) $contribErrors, 'ids' => (object) $ids],
        ];
    }

    public static function teacherPayload(Teacher $t): array
    {
        return [
            'uuid' => $t->uuid,
            'server_id' => $t->id,
            'number' => $t->number,
            'full_name' => $t->full_name,
            'phone' => $t->phone,
            'email' => $t->email,
            'is_active' => $t->is_active,
            'notes' => $t->notes,
            'deleted' => $t->trashed(),
            'updated_at' => $t->updated_at?->toIso8601String(),
        ];
    }

    public static function contributionPayload(Contribution $c): array
    {
        return [
            'uuid' => $c->uuid,
            'server_id' => $c->id,
            'teacher_uuid' => $c->teacher?->uuid,
            'year' => $c->year,
            'month' => $c->month,
            'amount' => $c->amount,
            'paid_at' => $c->paid_at?->format('Y-m-d'),
            'reference' => $c->reference,
            'notes' => $c->notes,
            'source' => $c->source,
            'recorded_by' => $c->recorder?->name ?? ($c->source === 'import' ? 'Uingizaji Excel' : null),
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
            'deleted' => $c->trashed(),
        ];
    }
}
