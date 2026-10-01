<?php

namespace App\Http\Controllers\Api;

use App\Jobs\SendContributionNotifications;
use App\Models\Contribution;
use App\Models\Teacher;
use App\Services\AppSettings;
use App\Services\ContributionLedger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ContributionController extends Controller
{
    public function __construct(private ContributionLedger $ledger, private AppSettings $settings) {}

    public function index(Request $request)
    {
        $year = $this->year($request);
        $query = Contribution::with(['teacher', 'recorder'])->where('year', $year);

        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('reference', 'like', "%{$search}%")
                    ->orWhere('notes', 'like', "%{$search}%")
                    ->orWhereHas('teacher', fn ($t) => $t->where('full_name', 'like', "%{$search}%"));
            });
        }
        if ($month = (int) $request->query('month')) {
            $query->where('month', $month);
        }
        if ($teacherId = (int) $request->query('teacher_id')) {
            $query->where('teacher_id', $teacherId);
        }
        if ($from = $request->query('from')) {
            $query->whereDate('paid_at', '>=', $from);
        }
        if ($to = $request->query('to')) {
            $query->whereDate('paid_at', '<=', $to);
        }

        $sum = (clone $query)->sum('amount');
        $page = $query->orderByDesc('created_at')->orderByDesc('id')
            ->paginate(min(200, max(5, (int) $request->query('per_page', 20))));

        return [
            'data' => collect($page->items())->map->toApi(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'sum' => (int) $sum,
            ],
        ];
    }

    public function nextReference(Request $request)
    {
        return ['reference' => $this->ledger->nextReference($this->year($request))];
    }

    /**
     * Records a payment. If the teacher already has a payment for the month the
     * request is rejected with 409 unless the client sends mode=additional,
     * which is how the "Ongeza muamala wa ziada" choice in the app is honoured.
     */
    public function store(Request $request)
    {
        $data = $this->validated($request);
        $existing = Contribution::with(['teacher', 'recorder'])
            ->where(['teacher_id' => $data['teacher_id'], 'year' => $data['year'], 'month' => $data['month']])
            ->get();

        if ($existing->isNotEmpty() && $request->input('mode') !== 'additional') {
            return response()->json([
                'message' => 'Mwalimu huyu tayari ana mchango uliorekodiwa kwa mwezi huu.',
                'code' => 'duplicate',
                'existing' => $existing->map->toApi(),
            ], 409);
        }

        $contribution = Contribution::create($data + [
            'reference' => $data['reference'] ?? $this->ledger->nextReference($data['year']),
            'source' => 'manual',
            'recorded_by' => $request->user()->id,
        ]);

        $notify = $request->boolean('notify', true) && ($this->settings->get('sms_enabled') || $this->settings->get('email_enabled'));
        if ($notify) {
            SendContributionNotifications::dispatchAfterResponse($contribution->id);
        }

        return response()->json($this->result($contribution, $notify), 201);
    }

    public function show(Contribution $contribution)
    {
        $contribution->load(['teacher', 'recorder']);

        return $contribution->toApi() + [
            'notifications' => \App\Models\NotificationLog::where('contribution_id', $contribution->id)->latest()->get(),
        ];
    }

    public function update(Request $request, Contribution $contribution)
    {
        $contribution->update($this->validated($request, $contribution));

        $notify = $request->boolean('notify', (bool) $this->settings->get('notify_on_update'));
        if ($notify) {
            SendContributionNotifications::dispatchAfterResponse($contribution->id, true);
        }

        return $this->result($contribution, $notify);
    }

    public function destroy(Contribution $contribution)
    {
        $teacher = $contribution->teacher;
        $year = $contribution->year;
        $contribution->delete();

        return [
            'message' => 'Mchango umefutwa.',
            'teacher' => $this->ledger->teacherDetail($teacher, $year),
        ];
    }

    public function notify(Contribution $contribution)
    {
        SendContributionNotifications::dispatchAfterResponse($contribution->id, true);

        return ['message' => 'Ujumbe unatumwa kwa mwalimu.'];
    }

    private function result(Contribution $contribution, bool $notified): array
    {
        $contribution->load(['teacher', 'recorder']);

        return [
            'contribution' => $contribution->toApi(),
            'teacher' => $this->ledger->teacherDetail($contribution->teacher, $contribution->year),
            'notified' => $notified,
            'channels' => array_keys(array_filter([
                'sms' => $notified && $this->settings->get('sms_enabled') && $contribution->teacher->phone,
                'email' => $notified && $this->settings->get('email_enabled') && $contribution->teacher->email,
            ])),
        ];
    }

    private function validated(Request $request, ?Contribution $contribution = null): array
    {
        $data = $request->validate([
            'teacher_id' => ['required', Rule::exists(Teacher::class, 'id')],
            'year' => ['required', 'integer', 'between:2000,2100'],
            'month' => ['required', 'integer', 'between:1,12'],
            'amount' => ['required', 'integer', 'min:1', 'max:100000000'],
            'paid_at' => ['required', 'date'],
            'reference' => [
                'nullable', 'string', 'max:60',
                Rule::unique('contributions', 'reference')->withoutTrashed()->ignore($contribution?->id),
            ],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'teacher_id.required' => 'Chagua mwalimu.',
            'month.required' => 'Chagua mwezi.',
            'amount.required' => 'Weka kiasi.',
            'amount.min' => 'Kiasi lazima kiwe zaidi ya sifuri.',
            'paid_at.required' => 'Weka tarehe ya malipo.',
            'reference.unique' => 'Namba hii ya kumbukumbu tayari imetumika.',
        ]);

        return array_filter($data, fn ($v) => $v !== null) + ['notes' => $data['notes'] ?? null];
    }
}
