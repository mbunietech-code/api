<?php

namespace App\Http\Controllers\Api;

use App\Models\Teacher;
use App\Services\ContributionLedger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TeacherController extends Controller
{
    public function __construct(private ContributionLedger $ledger) {}

    /**
     * Full contribution matrix. Filtering/sorting is done here so every client
     * gets identical results; the list is small (under a few hundred rows).
     */
    public function index(Request $request)
    {
        $year = $this->year($request);
        $month = (int) $request->query('month', 0);
        $month = $month >= 1 && $month <= 12 ? $month : null;

        $rows = $this->ledger->teacherRows($year, $month);

        if ($search = trim((string) $request->query('search', ''))) {
            $needle = mb_strtoupper($search);
            $rows = $rows->filter(fn ($r) => str_contains(mb_strtoupper($r['full_name']), $needle)
                || str_contains((string) $r['phone'], $search));
        }
        if (in_array($status = $request->query('status'), [ContributionLedger::PAID, ContributionLedger::PARTIAL, ContributionLedger::UNPAID], true)) {
            $rows = $rows->where('status', $status);
        }
        if ($request->boolean('active_only')) {
            $rows = $rows->where('is_active', true);
        }

        $rows = match ($request->query('sort', 'number')) {
            'name' => $rows->sortBy('full_name'),
            'name_desc' => $rows->sortByDesc('full_name'),
            'total' => $rows->sortBy('total'),
            'total_desc' => $rows->sortByDesc('total'),
            default => $rows,
        };

        $all = $this->ledger->teacherRows($year);

        return [
            'year' => $year,
            'month' => $month,
            'monthly_amount' => app(\App\Services\AppSettings::class)->monthlyAmount(),
            'data' => $rows->values(),
            'totals' => [
                'months' => collect(range(0, 11))->map(fn ($i) => $all->sum(fn ($r) => $r['months'][$i]))->all(),
                'total' => $all->sum('total'),
                'count' => $all->count(),
                'paid' => $all->where('status', ContributionLedger::PAID)->count(),
                'partial' => $all->where('status', ContributionLedger::PARTIAL)->count(),
                'unpaid' => $all->where('status', ContributionLedger::UNPAID)->count(),
            ],
        ];
    }

    public function show(Request $request, Teacher $teacher)
    {
        return $this->ledger->teacherDetail($teacher, $this->year($request));
    }

    public function store(Request $request)
    {
        $teacher = Teacher::create($this->validated($request));

        return response()->json($this->ledger->teacherDetail($teacher, $this->year($request)), 201);
    }

    public function update(Request $request, Teacher $teacher)
    {
        $teacher->update($this->validated($request, $teacher));

        return $this->ledger->teacherDetail($teacher, $this->year($request));
    }

    public function destroy(Teacher $teacher)
    {
        if ($teacher->contributions()->exists()) {
            $teacher->update(['is_active' => false]);

            return ['message' => 'Mwalimu ana michango iliyorekodiwa, hivyo amezimwa badala ya kufutwa.', 'deactivated' => true];
        }
        $teacher->delete();

        return ['message' => 'Mwalimu amefutwa.', 'deactivated' => false];
    }

    private function validated(Request $request, ?Teacher $teacher = null): array
    {
        $request->merge(['name_key' => Teacher::keyFor((string) $request->input('full_name', ''))]);

        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'name_key' => [Rule::unique('teachers', 'name_key')->ignore($teacher?->id)],
            'number' => ['nullable', 'integer', 'min:1'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+ ()-]{9,20}$/'],
            'email' => ['nullable', 'email', 'max:150'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ], [
            'name_key.unique' => 'Mwalimu mwenye jina hili tayari yupo.',
            'phone.regex' => 'Namba ya simu si sahihi.',
        ]);
        unset($data['name_key']);

        if (! $teacher && empty($data['number'])) {
            $data['number'] = (int) Teacher::max('number') + 1;
        }

        return $data;
    }
}
