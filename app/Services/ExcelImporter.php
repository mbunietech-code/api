<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Teacher;
use App\Support\Months;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;

/**
 * Reads the school's "MICHANGO YA USTAWI WA JAMII" spreadsheet layout:
 * No. | JINA LA MWALIMU | JAN .. DEC | TOTAL
 */
class ExcelImporter
{
    public function __construct(private ContributionLedger $ledger) {}

    public function parse(string $path, ?int $year = null): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getActiveSheet();
        $data = $sheet->toArray(null, true, false, false);

        [$headerIndex, $columns] = $this->locateHeader($data);
        $year ??= $this->detectYear($data, $sheet->getTitle()) ?? (int) now()->year;

        $rows = [];
        $errors = [];
        $seen = [];

        foreach (array_slice($data, $headerIndex + 1, null, true) as $index => $cells) {
            $excelRow = $index + 1;
            $name = Teacher::cleanName((string) ($cells[$columns['name']] ?? ''));
            if ($name === '') {
                continue;
            }
            if (in_array(mb_strtoupper($name), ['JUMLA', 'TOTAL', 'JUMLA KUU'], true)) {
                continue;
            }

            $issues = [];
            $months = [];
            foreach (range(1, 12) as $m) {
                $raw = isset($columns['months'][$m]) ? ($cells[$columns['months'][$m]] ?? null) : null;
                [$amount, $problem] = $this->amount($raw);
                $months[] = $amount;
                if ($problem) {
                    $issues[] = Months::SHORT[$m].': '.$problem;
                }
            }

            $computed = array_sum($months);
            $excelTotal = null;
            if (isset($columns['total'])) {
                [$excelTotal] = $this->amount($cells[$columns['total']] ?? null);
                if ($excelTotal !== $computed && ($cells[$columns['total']] ?? '') !== '') {
                    $issues[] = 'Jumla ya Excel ('.number_format($excelTotal).') hailingani na jumla ya miezi ('.number_format($computed).').';
                }
            }

            $key = Teacher::keyFor($name);
            if (isset($seen[$key])) {
                $issues[] = 'Jina limejirudia (safu '.$seen[$key].').';
            }
            $seen[$key] ??= $excelRow;

            $number = $cells[$columns['number']] ?? null;
            $rows[] = [
                'row' => $excelRow,
                'number' => is_numeric($number) ? (int) $number : null,
                'name' => $name,
                'months' => $months,
                'total' => $computed,
                'excel_total' => $excelTotal,
                'issues' => $issues,
            ];

            foreach ($issues as $issue) {
                $errors[] = ['row' => $excelRow, 'name' => $name, 'message' => $issue];
            }
        }

        if (! $rows) {
            throw new RuntimeException('Hakuna safu za walimu zilizopatikana kwenye faili hili.');
        }

        return $this->annotate($rows, $year, $errors);
    }

    /** Marks new teachers and teacher/month pairs that already have contributions. */
    private function annotate(array $rows, int $year, array $errors): array
    {
        $teachers = Teacher::pluck('id', 'name_key');
        $matrix = $this->ledger->monthMatrix($year);
        $duplicates = [];

        foreach ($rows as &$row) {
            $teacherId = $teachers[Teacher::keyFor($row['name'])] ?? null;
            $row['teacher_id'] = $teacherId;
            $row['is_new'] = $teacherId === null;
            $row['duplicate_months'] = [];

            if ($teacherId) {
                foreach ($row['months'] as $i => $amount) {
                    $existing = $matrix[$teacherId][$i + 1] ?? 0;
                    if ($amount > 0 && $existing > 0) {
                        $row['duplicate_months'][] = $i + 1;
                        $duplicates[] = [
                            'row' => $row['row'],
                            'name' => $row['name'],
                            'month' => $i + 1,
                            'existing_amount' => $existing,
                            'new_amount' => $amount,
                        ];
                    }
                }
            }
        }
        unset($row);

        return [
            'year' => $year,
            'teachers_count' => count($rows),
            'new_teachers' => count(array_filter($rows, fn ($r) => $r['is_new'])),
            'records_count' => array_sum(array_map(fn ($r) => count(array_filter($r['months'])), $rows)),
            'total_amount' => array_sum(array_column($rows, 'total')),
            'rows' => $rows,
            'errors' => $errors,
            'duplicates' => $duplicates,
        ];
    }

    /**
     * @param  array<int, array{name: string, number?: int|null, months: array<int, int>}>  $rows
     * @param  string  $duplicateMode  skip|replace
     */
    public function commit(array $rows, int $year, string $duplicateMode, ?int $userId): array
    {
        $stats = ['teachers_created' => 0, 'records_created' => 0, 'records_skipped' => 0, 'records_replaced' => 0, 'amount' => 0];

        DB::transaction(function () use ($rows, $year, $duplicateMode, $userId, &$stats) {
            $matrix = $this->ledger->monthMatrix($year);

            foreach ($rows as $row) {
                $name = Teacher::cleanName($row['name']);
                $teacher = Teacher::withTrashed()->firstWhere('name_key', Teacher::keyFor($name));
                if ($teacher?->trashed()) {
                    $teacher->restore();
                }
                if (! $teacher) {
                    $teacher = Teacher::create(['full_name' => $name, 'number' => $row['number'] ?? null]);
                    $stats['teachers_created']++;
                }

                foreach (array_values($row['months']) as $i => $amount) {
                    $amount = (int) $amount;
                    $month = $i + 1;
                    if ($amount <= 0) {
                        continue;
                    }

                    if (($matrix[$teacher->id][$month] ?? 0) > 0) {
                        if ($duplicateMode !== 'replace') {
                            $stats['records_skipped']++;

                            continue;
                        }
                        Contribution::where(['teacher_id' => $teacher->id, 'year' => $year, 'month' => $month])->delete();
                        $stats['records_replaced']++;
                    }

                    Contribution::create([
                        'teacher_id' => $teacher->id,
                        'year' => $year,
                        'month' => $month,
                        'amount' => $amount,
                        'paid_at' => null,
                        'reference' => sprintf('IMP-%d-%04d-%02d', $year, $teacher->id, $month),
                        'notes' => 'Imeingizwa kutoka Excel',
                        'source' => 'import',
                        'recorded_by' => $userId,
                    ]);
                    $stats['records_created']++;
                    $stats['amount'] += $amount;
                }
            }
        });

        return $stats;
    }

    private function locateHeader(array $data): array
    {
        foreach (array_slice($data, 0, 15, true) as $index => $cells) {
            $columns = ['number' => null, 'name' => null, 'total' => null, 'months' => []];
            foreach ($cells as $col => $value) {
                $text = strtoupper(trim((string) $value));
                if ($text === '') {
                    continue;
                }
                if (str_contains($text, 'JINA')) {
                    $columns['name'] = $col;
                } elseif (in_array($text, ['NO', 'NO.', 'NA', 'NA.', 'S/N'], true)) {
                    $columns['number'] = $col;
                } elseif (in_array($text, ['TOTAL', 'JUMLA'], true)) {
                    $columns['total'] = $col;
                } elseif ($month = Months::fromHeader($text)) {
                    $columns['months'][$month] = $col;
                }
            }
            if ($columns['name'] !== null && count($columns['months']) >= 6) {
                return [$index, $columns];
            }
        }

        throw new RuntimeException('Muundo wa faili haujatambuliwa. Hakikisha lina safu "JINA LA MWALIMU" na miezi JAN - DEC.');
    }

    private function detectYear(array $data, string $sheetTitle): ?int
    {
        foreach (array_merge([$sheetTitle], ...array_map(fn ($r) => array_map('strval', $r), array_slice($data, 0, 5))) as $text) {
            if (preg_match('/\b(20\d{2})\b/', $text, $m)) {
                return (int) $m[1];
            }
        }

        return null;
    }

    /** @return array{0: int, 1: string|null} */
    private function amount(mixed $raw): array
    {
        if ($raw === null || $raw === '' || $raw === '-') {
            return [0, null];
        }
        if (is_numeric($raw)) {
            $value = (float) $raw;

            return $value < 0 ? [0, 'kiasi hasi kimepuuzwa'] : [(int) round($value), null];
        }
        $clean = str_replace([',', ' ', 'TZS', 'Tsh', 'TSH', '/='], '', (string) $raw);
        if (is_numeric($clean)) {
            return [(int) round((float) $clean), null];
        }

        return [0, 'thamani "'.$raw.'" si namba'];
    }
}
