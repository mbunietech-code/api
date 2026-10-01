<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Teacher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single source of truth for every derived figure: per-teacher monthly amounts,
 * annual totals, payment status, monthly totals and dashboard statistics.
 */
class ContributionLedger
{
    public const PAID = 'amelipa';

    public const PARTIAL = 'sehemu';

    public const UNPAID = 'hajalipa';

    public function __construct(private AppSettings $settings) {}

    /** Number of months that should have been paid by today for the given year. */
    public function expectedMonths(int $year): int
    {
        $now = now();
        if ($year < $now->year) {
            return 12;
        }

        return $year === $now->year ? $now->month : 0;
    }

    /** The month treated as "mwezi huu" for a year. */
    public function currentMonth(int $year): int
    {
        $now = now();

        return $year === $now->year ? $now->month : ($year < $now->year ? 12 : 1);
    }

    public function monthStatus(int $amount): string
    {
        if ($amount <= 0) {
            return self::UNPAID;
        }

        return $amount >= $this->settings->monthlyAmount() ? self::PAID : self::PARTIAL;
    }

    /**
     * @return array<int, array<int, int>> teacher_id => [1..12 => amount]
     */
    public function monthMatrix(int $year): array
    {
        $rows = Contribution::query()
            ->where('year', $year)
            ->select('teacher_id', 'month', DB::raw('SUM(amount) as total'))
            ->groupBy('teacher_id', 'month')
            ->get();

        $matrix = [];
        foreach ($rows as $row) {
            $matrix[$row->teacher_id][(int) $row->month] = (int) $row->total;
        }

        return $matrix;
    }

    public function teacherRow(Teacher $teacher, array $months, int $year, ?int $statusMonth = null): array
    {
        $amounts = [];
        for ($m = 1; $m <= 12; $m++) {
            $amounts[$m] = (int) ($months[$m] ?? 0);
        }
        $total = array_sum($amounts);
        $rate = $this->settings->monthlyAmount();
        $expected = $this->expectedMonths($year);

        $paidMonths = count(array_filter($amounts, fn ($a) => $a >= $rate));
        $paidInPeriod = count(array_filter(array_slice($amounts, 0, $expected, true), fn ($a) => $a >= $rate));

        if ($statusMonth) {
            $status = $this->monthStatus($amounts[$statusMonth]);
        } elseif ($total === 0) {
            $status = self::UNPAID;
        } elseif ($paidInPeriod >= max($expected, 1)) {
            $status = self::PAID;
        } else {
            $status = self::PARTIAL;
        }

        return [
            'id' => $teacher->id,
            'number' => $teacher->number,
            'full_name' => $teacher->full_name,
            'phone' => $teacher->phone,
            'email' => $teacher->email,
            'is_active' => $teacher->is_active,
            'months' => array_values($amounts),
            'total' => $total,
            'months_paid' => $paidMonths,
            'months_unpaid' => max(0, $expected - $paidInPeriod),
            'expected_months' => $expected,
            'status' => $status,
        ];
    }

    /** @return Collection<int, array> */
    public function teacherRows(int $year, ?int $statusMonth = null, bool $activeOnly = false): Collection
    {
        $matrix = $this->monthMatrix($year);

        return Teacher::query()
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->orderByRaw('number IS NULL, number')
            ->orderBy('full_name')
            ->get()
            ->map(fn (Teacher $t) => $this->teacherRow($t, $matrix[$t->id] ?? [], $year, $statusMonth));
    }

    /** @return array<int, array> one entry per month */
    public function monthlySummary(int $year): array
    {
        $rows = $this->teacherRows($year, null, true);
        $rate = $this->settings->monthlyAmount();
        $count = $rows->count();
        $summary = [];

        for ($m = 1; $m <= 12; $m++) {
            $amounts = $rows->map(fn ($r) => $r['months'][$m - 1]);
            $paid = $amounts->filter(fn ($a) => $a >= $rate)->count();
            $partial = $amounts->filter(fn ($a) => $a > 0 && $a < $rate)->count();
            $summary[] = [
                'month' => $m,
                'total' => (int) $amounts->sum(),
                'paid_count' => $paid,
                'partial_count' => $partial,
                'unpaid_count' => $count - $paid - $partial,
                'expected_total' => $count * $rate,
            ];
        }

        return $summary;
    }

    public function dashboard(int $year, ?int $month = null): array
    {
        $month ??= $this->currentMonth($year);
        $monthly = $this->monthlySummary($year);
        $current = $monthly[$month - 1];
        $previous = $month > 1 ? $monthly[$month - 2] : null;

        $recent = Contribution::with(['teacher', 'recorder'])
            ->where('year', $year)
            ->latest('created_at')
            ->latest('id')
            ->limit(8)
            ->get()
            ->map->toApi();

        return [
            'year' => $year,
            'month' => $month,
            'total_teachers' => Teacher::where('is_active', true)->count(),
            'total_contributions' => array_sum(array_column($monthly, 'total')),
            'month_total' => $current['total'],
            'previous_month_total' => $previous['total'] ?? null,
            'paid_count' => $current['paid_count'],
            'partial_count' => $current['partial_count'],
            'unpaid_count' => $current['unpaid_count'],
            'expected_month_total' => $current['expected_total'],
            'transactions_count' => Contribution::where('year', $year)->count(),
            'monthly' => $monthly,
            'recent' => $recent,
        ];
    }

    public function teacherDetail(Teacher $teacher, int $year): array
    {
        $matrix = $this->monthMatrix($year);
        $row = $this->teacherRow($teacher, $matrix[$teacher->id] ?? [], $year);

        $history = $teacher->contributions()
            ->with(['teacher', 'recorder'])
            ->where('year', $year)
            ->orderByDesc('paid_at')
            ->orderByDesc('id')
            ->get();

        $row['monthly'] = collect($row['months'])->map(fn ($amount, $i) => [
            'month' => $i + 1,
            'amount' => $amount,
            'status' => $this->monthStatus($amount),
            'paid_at' => $history->where('month', $i + 1)->first()?->paid_at?->format('Y-m-d'),
        ])->all();
        $row['latest_payment'] = $history->first()?->toApi();
        $row['history'] = $history->map->toApi()->values();
        $row['notes'] = $teacher->notes;

        return $row;
    }

    public function nextReference(int $year): string
    {
        $prefix = $this->settings->get('reference_prefix') ?: 'UST';
        $next = (int) Contribution::withTrashed()->max('id') + 1;

        do {
            $reference = sprintf('%s-%d-%06d', $prefix, $year, $next++);
        } while (Contribution::where('reference', $reference)->exists());

        return $reference;
    }
}
