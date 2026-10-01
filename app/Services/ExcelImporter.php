<?php

namespace App\Services;

use App\Models\Contribution;
use App\Models\Teacher;
use App\Support\Months;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

/**
 * Imports contribution data from almost any spreadsheet.
 *
 * 1. Every sheet is scanned for a header row and each column gets a role
 *    (name, number, month:1..12, total, phone, email, or for transaction
 *    lists: amount, month, date, reference, notes).
 * 2. Two layouts are understood:
 *    - "wide": one row per teacher, one column per month (the school's sheet);
 *    - "long": one row per payment (name + month or date + amount).
 * 3. The detected mapping is returned with the preview so the user can
 *    correct it; a corrected mapping is sent back and used as-is.
 * 4. Names are matched to existing teachers exactly, with the words in a
 *    different order, or by close spelling (suggestion the user confirms).
 */
class ExcelImporter
{
    public const ROLES = [
        'ignore', 'name', 'number', 'phone', 'email', 'total',
        'amount', 'month', 'date', 'reference', 'notes',
        'month:1', 'month:2', 'month:3', 'month:4', 'month:5', 'month:6',
        'month:7', 'month:8', 'month:9', 'month:10', 'month:11', 'month:12',
    ];

    private const NAME_WORDS = ['JINA', 'JILA', 'MAJINA', 'NAME', 'MWALIMU', 'WALIMU', 'MTUMISHI', 'WATUMISHI', 'MFANYAKAZI', 'MCHANGIAJI', 'MWANACHAMA', 'STAFF', 'TEACHER'];

    private const TOTAL_ROW_WORDS = ['JUMLA', 'TOTAL', 'TOTALS', 'JUMLA KUU', 'GRAND TOTAL', 'JUMLA YA MICHANGO', 'SUM'];

    private const MAX_ROWS = 5000;

    public function __construct(private ContributionLedger $ledger) {}

    /**
     * @param  array|null  $mapping  {layout, header_row, columns: {"A": role, ...}} from a previous preview
     */
    public function parse(string $path, ?int $year = null, ?array $mapping = null, ?string $sheet = null, ?string $fileName = null, string $kind = 'contributions'): array
    {
        $teachersOnly = $kind === 'teachers';
        $tables = $this->load($path);
        $sheets = array_keys($tables);
        if ($sheet !== null && ! isset($tables[$sheet])) {
            throw new RuntimeException("Sheet \"{$sheet}\" haipo kwenye faili hili.");
        }

        if ($mapping) {
            $sheet ??= $sheets[0];
            $map = $this->normalizeMapping($mapping, $tables[$sheet], $teachersOnly);
            $map['auto'] = false;
        } else {
            $best = null;
            foreach ($sheet !== null ? [$sheet] : $sheets as $name) {
                $candidate = $this->detect($tables[$name], $teachersOnly);
                if ($best === null || $candidate['score'] > $best[1]['score']) {
                    $best = [$name, $candidate];
                }
            }
            [$sheet, $map] = $best;
            $map['auto'] = true;
        }

        $table = $tables[$sheet];
        $year ??= $this->detectYear($table, $sheet, $fileName, $map) ?? (int) now()->year;

        $base = [
            'year' => $year,
            'sheets' => $sheets,
            'sheet' => $sheet,
            'layout' => $map['layout'],
            'header_row' => $map['header_row'],
            'mapping' => $this->publicMapping($map),
            'columns' => $this->describeColumns($table, $map),
            'auto_detected' => $map['auto'],
            'kind' => $kind,
        ];

        if ($teachersOnly) {
            if (! in_array('name', $map['columns'], true)) {
                return $base + $this->emptyResult('Chagua safu yenye majina ya walimu ili kuendelea.');
            }
            [$rows, $errors] = $this->readTeachers($table, $map);
            if (! $rows) {
                return $base + $this->emptyResult('Hakuna majina ya walimu yaliyopatikana. Kagua safu ya kichwa na safu ya majina.');
            }

            return $base + $this->annotateTeachers($rows, $errors) + ['needs_mapping' => false, 'message' => null];
        }

        if (! $this->usable($map)) {
            return $base + $this->emptyResult($map['layout'] === 'long'
                ? 'Chagua safu za Jina, Kiasi na Mwezi (au Tarehe) ili kuendelea.'
                : 'Haikuweza kutambua safu ya majina na miezi. Chagua maana ya kila safu hapa chini.');
        }

        [$rows, $errors] = $map['layout'] === 'long' ? $this->readLong($table, $map) : $this->readWide($table, $map);
        if (! $rows) {
            return $base + $this->emptyResult('Hakuna safu za walimu zilizopatikana kwa ramani hii ya safu. Kagua safu ya kichwa na safu ya majina.');
        }

        return $base + $this->annotate($rows, $year, $errors) + ['needs_mapping' => false, 'message' => null];
    }

    // ----------------------------------------------------------- reading

    /** @return array<string, array{raw: array, text: array, cols: int[]}> */
    private function load(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $book = $reader->load($path);
        $tables = [];
        foreach ($book->getWorksheetIterator() as $ws) {
            $tables[$ws->getTitle()] = $this->table($ws);
        }

        return $tables;
    }

    private function table(Worksheet $ws): array
    {
        $maxRow = min($ws->getHighestDataRow(), self::MAX_ROWS);
        $range = 'A1:'.$ws->getHighestDataColumn().$maxRow;
        $raw = $ws->rangeToArray($range, null, true, false, false);
        $text = $ws->rangeToArray($range, null, true, true, false);

        // Only columns that hold something (sheets often report formatted but empty columns up to AN).
        $used = [];
        foreach ($text as $cells) {
            foreach ($cells as $c => $v) {
                if ($v !== null && trim((string) $v) !== '') {
                    $used[$c] = true;
                }
            }
        }
        ksort($used);

        return ['raw' => $raw, 'text' => $text, 'cols' => array_keys($used)];
    }

    private static function letter(int $index): string
    {
        return Coordinate::stringFromColumnIndex($index + 1);
    }

    private static function index(string $letter): int
    {
        return Coordinate::columnIndexFromString(strtoupper($letter)) - 1;
    }

    private static function str(mixed $v): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $v));
    }

    // --------------------------------------------------------- detection

    /** Role suggested by a header cell, or null. */
    public static function headerRole(mixed $value): ?string
    {
        $t = mb_strtoupper(self::str($value));
        if ($t === '' || mb_strlen($t) > 40) {
            return null;
        }
        $has = fn (array $words) => (bool) array_filter($words, fn ($w) => str_contains($t, $w));

        if ($has(['SIMU', 'PHONE', 'TEL', 'MOBILE', 'NAMBA YA SIMU'])) {
            return 'phone';
        }
        if ($has(['EMAIL', 'E-MAIL', 'BARUA PEPE'])) {
            return 'email';
        }
        if ($has(self::NAME_WORDS)) {
            return 'name';
        }
        if (in_array(rtrim($t, '.'), ['NO', 'NA', 'S/N', 'SN', '#', 'NAMBA', 'NUM', 'NAMBARI', 'S/NO', 'SNO', 'NUMBER'], true)) {
            return 'number';
        }
        if (str_starts_with($t, 'JUMLA') || str_contains($t, 'TOTAL')) {
            return 'total';
        }
        if ($has(['TAREHE', 'DATE'])) {
            return 'date';
        }
        if ($has(['MWEZI', 'MONTH'])) {
            return 'month';
        }
        if ($has(['KUMBUKUMBU', 'REF', 'RISITI', 'RECEIPT', 'STAKABADHI'])) {
            return 'reference';
        }
        if ($has(['MAELEZO', 'NOTE', 'MAONI', 'REMARK'])) {
            return 'notes';
        }
        if ($has(['KIASI', 'AMOUNT', 'MCHANGO', 'MALIPO', 'TSH', 'TZS', 'FEDHA', 'PAID'])) {
            return 'amount';
        }
        if ($m = Months::parse($t)) {
            return 'month:'.$m;
        }

        return null;
    }

    private function detect(array $table, bool $teachersOnly = false): array
    {
        $best = ['score' => 0, 'layout' => 'wide', 'header_row' => 0, 'columns' => []];

        foreach (array_slice($table['text'], 0, 30, true) as $r => $cells) {
            $roles = [];
            foreach ($table['cols'] as $c) {
                if ($role = self::headerRole($cells[$c] ?? null)) {
                    // First column wins for single roles (e.g. two "JINA" columns).
                    if (! str_starts_with($role, 'month:') && in_array($role, $roles, true)) {
                        continue;
                    }
                    $roles[$c] = $role;
                }
            }
            if ($teachersOnly) {
                // Teacher lists only need a name column; contact columns raise confidence.
                $roles = array_filter($roles, fn ($x) => in_array($x, ['name', 'number', 'phone', 'email', 'notes'], true));
                if (! in_array('name', $roles, true)) {
                    continue;
                }
                $score = 25 + 5 * (count($roles) - 1);
                if ($score > $best['score']) {
                    $best = ['score' => $score, 'layout' => 'teachers', 'header_row' => $r + 1, 'columns' => $roles];
                }

                continue;
            }

            $months = count(array_unique(array_filter($roles, fn ($x) => str_starts_with($x, 'month:'))));
            $hasAmount = in_array('amount', $roles, true);
            $hasWhen = in_array('month', $roles, true) || in_array('date', $roles, true);

            // One month column is enough when the same row also names the people.
            if ($months >= 2 || ($months === 1 && in_array('name', $roles, true))) {
                $layout = 'wide';
                $score = $months * 10;
            } elseif ($hasAmount && $hasWhen) {
                $layout = 'long';
                $score = 40;
            } else {
                continue;
            }

            if (! in_array('name', $roles, true) && ($guess = $this->guessNameColumn($table, $r + 1, array_keys($roles))) !== null) {
                $roles[$guess] = 'name';
            }
            if (in_array('name', $roles, true)) {
                $score += 25;
            }
            if ($score > $best['score']) {
                $best = ['score' => $score, 'layout' => $layout, 'header_row' => $r + 1, 'columns' => $roles];
            }
        }

        if ($best['score'] === 0) {
            if ($teachersOnly) {
                $best['layout'] = 'teachers';
            }
            // No header at all: at least propose the column that looks like names.
            $guess = $this->guessNameColumn($table, 0, []);
            $best['columns'] = $guess === null ? [] : [$guess => 'name'];
        }

        return $best;
    }

    /** Column (below $fromRow) whose cells are mostly words, i.e. names. */
    private function guessNameColumn(array $table, int $fromRow, array $exclude): ?int
    {
        $best = null;
        $bestCount = 0;
        $rows = array_slice($table['text'], $fromRow, 40);
        foreach ($table['cols'] as $c) {
            if (in_array($c, $exclude, true)) {
                continue;
            }
            $words = 0;
            $filled = 0;
            foreach ($rows as $cells) {
                $v = self::str($cells[$c] ?? '');
                if ($v === '') {
                    continue;
                }
                $filled++;
                if (preg_match('/^\p{L}[\p{L}\'.\- ]{2,}$/u', $v) && str_contains($v, ' ')) {
                    $words++;
                }
            }
            if ($filled > 0 && $words / $filled >= 0.6 && $words > $bestCount) {
                $best = $c;
                $bestCount = $words;
            }
        }

        return $best;
    }

    private function normalizeMapping(array $input, array $table, bool $teachersOnly = false): array
    {
        $layout = $teachersOnly ? 'teachers' : (($input['layout'] ?? 'wide') === 'long' ? 'long' : 'wide');
        $header = max(0, (int) ($input['header_row'] ?? 0));
        $columns = [];
        foreach ((array) ($input['columns'] ?? []) as $letter => $role) {
            if (! is_string($letter) || ! preg_match('/^[A-Z]{1,3}$/i', $letter) || ! in_array($role, self::ROLES, true) || $role === 'ignore') {
                continue;
            }
            $columns[self::index($letter)] = $role;
        }

        return ['score' => 0, 'layout' => $layout, 'header_row' => $header, 'columns' => $columns];
    }

    private function publicMapping(array $map): array
    {
        $columns = [];
        foreach ($map['columns'] as $c => $role) {
            $columns[self::letter($c)] = $role;
        }

        return ['layout' => $map['layout'], 'header_row' => $map['header_row'], 'columns' => (object) $columns];
    }

    private function usable(array $map): bool
    {
        $roles = array_values($map['columns']);
        if (! in_array('name', $roles, true)) {
            return false;
        }
        if ($map['layout'] === 'long') {
            return in_array('amount', $roles, true) && (in_array('month', $roles, true) || in_array('date', $roles, true));
        }

        return (bool) array_filter($roles, fn ($r) => str_starts_with($r, 'month:'));
    }

    private function describeColumns(array $table, array $map): array
    {
        $out = [];
        $h = $map['header_row'];
        foreach ($table['cols'] as $c) {
            $samples = [];
            foreach (array_slice($table['text'], $h, 60) as $cells) {
                $v = self::str($cells[$c] ?? '');
                if ($v !== '') {
                    $samples[] = mb_substr($v, 0, 40);
                }
                if (count($samples) === 3) {
                    break;
                }
            }
            $out[] = [
                'key' => self::letter($c),
                'header' => $h > 0 ? self::str($table['text'][$h - 1][$c] ?? '') : '',
                'samples' => $samples,
                'role' => $map['columns'][$c] ?? 'ignore',
            ];
        }

        return $out;
    }

    private function detectYear(array $table, string $sheet, ?string $fileName, array $map): ?int
    {
        $texts = [$sheet, (string) $fileName];
        foreach (array_slice($table['text'], 0, max(6, $map['header_row'])) as $cells) {
            foreach ($cells as $v) {
                $texts[] = (string) $v;
            }
        }
        foreach ($texts as $text) {
            if (preg_match('/(?<!\d)(20\d{2})(?!\d)/', $text, $m)) {
                return (int) $m[1];
            }
        }

        // Transaction lists: most common year among the dates.
        $dateCol = array_search('date', $map['columns'], true);
        if ($dateCol !== false) {
            $years = [];
            foreach (array_slice($table['raw'], $map['header_row']) as $i => $cells) {
                if ($d = $this->date($cells[$dateCol] ?? null, $table['text'][$map['header_row'] + $i][$dateCol] ?? null)) {
                    $years[] = (int) substr($d, 0, 4);
                }
            }
            if ($years) {
                $counts = array_count_values($years);
                arsort($counts);

                return (int) array_key_first($counts);
            }
        }

        return null;
    }

    // ------------------------------------------------------- extraction

    private function isTotalRow(string $name): bool
    {
        $upper = mb_strtoupper($name);

        return in_array($upper, self::TOTAL_ROW_WORDS, true) || str_starts_with($upper, 'JUMLA ') || is_numeric(str_replace([',', ' '], '', $name));
    }

    /** @return array{0: array, 1: array} */
    private function readWide(array $table, array $map): array
    {
        $col = fn (string $role) => array_search($role, $map['columns'], true);
        $nameCol = $col('name');
        $rows = [];
        $errors = [];
        $byKey = [];

        foreach (array_slice($table['raw'], $map['header_row'], null, true) as $i => $raw) {
            $text = $table['text'][$i];
            $excelRow = $i + 1;
            $name = Teacher::cleanName((string) ($text[$nameCol] ?? ''));
            if ($name === '' || $this->isTotalRow($name)) {
                continue;
            }

            $issues = [];
            $months = array_fill(0, 12, 0);
            foreach ($map['columns'] as $c => $role) {
                if (! str_starts_with($role, 'month:')) {
                    continue;
                }
                $m = (int) substr($role, 6);
                [$amount, $problem] = $this->amount($raw[$c] ?? null);
                $months[$m - 1] += $amount;
                if ($problem) {
                    $issues[] = Months::SHORT[$m].': '.$problem;
                }
            }
            $total = array_sum($months);

            $excelTotal = null;
            if (($tc = $col('total')) !== false && self::str($text[$tc] ?? '') !== '') {
                [$excelTotal] = $this->amount($raw[$tc] ?? null);
                if ($excelTotal !== $total) {
                    $issues[] = 'Jumla ya Excel ('.number_format($excelTotal).') hailingani na jumla ya miezi ('.number_format($total).').';
                }
            }

            $row = [
                'row' => $excelRow,
                'number' => ($nc = $col('number')) !== false && is_numeric($raw[$nc] ?? null) ? (int) $raw[$nc] : null,
                'name' => $name,
                'phone' => ($pc = $col('phone')) !== false ? (self::str($text[$pc] ?? '') ?: null) : null,
                'email' => ($ec = $col('email')) !== false ? (self::str($text[$ec] ?? '') ?: null) : null,
                'months' => $months,
                'paid_dates' => array_fill(0, 12, null),
                'total' => $total,
                'excel_total' => $excelTotal,
                'issues' => $issues,
            ];

            $key = Teacher::keyFor($name);
            if (isset($byKey[$key])) {
                // Same person listed twice: add the amounts to the first row.
                $first = &$rows[$byKey[$key]];
                foreach ($months as $m => $a) {
                    $first['months'][$m] += $a;
                }
                $first['total'] += $total;
                $first['issues'][] = "Jina limejirudia kwenye safu {$excelRow}; michango imeunganishwa.";
                unset($first);
                $errors[] = ['row' => $excelRow, 'name' => $name, 'message' => 'Jina limejirudia; michango imeunganishwa na safu ya kwanza.'];

                continue;
            }
            $byKey[$key] = count($rows);
            $rows[] = $row;
            foreach ($issues as $issue) {
                $errors[] = ['row' => $excelRow, 'name' => $name, 'message' => $issue];
            }
        }

        return [$rows, $errors];
    }

    /** One row per payment → grouped per teacher and month. */
    private function readLong(array $table, array $map): array
    {
        $col = fn (string $role) => array_search($role, $map['columns'], true);
        $nameCol = $col('name');
        $amountCol = $col('amount');
        $monthCol = $col('month');
        $dateCol = $col('date');
        $rows = [];
        $errors = [];
        $byKey = [];

        foreach (array_slice($table['raw'], $map['header_row'], null, true) as $i => $raw) {
            $text = $table['text'][$i];
            $excelRow = $i + 1;
            $name = Teacher::cleanName((string) ($text[$nameCol] ?? ''));
            if ($name === '' || $this->isTotalRow($name)) {
                continue;
            }

            [$amount, $problem] = $this->amount($raw[$amountCol] ?? null);
            $date = $dateCol !== false ? $this->date($raw[$dateCol] ?? null, $text[$dateCol] ?? null) : null;
            $month = $monthCol !== false ? Months::parse($raw[$monthCol] ?? null, true) ?? Months::parse($text[$monthCol] ?? null, true) : null;
            $month ??= $date ? (int) substr($date, 5, 2) : null;

            $key = Teacher::keyFor($name);
            if (! isset($byKey[$key])) {
                $byKey[$key] = count($rows);
                $rows[] = [
                    'row' => $excelRow,
                    'number' => ($nc = $col('number')) !== false && is_numeric($raw[$nc] ?? null) ? (int) $raw[$nc] : null,
                    'name' => $name,
                    'phone' => ($pc = $col('phone')) !== false ? (self::str($text[$pc] ?? '') ?: null) : null,
                    'email' => ($ec = $col('email')) !== false ? (self::str($text[$ec] ?? '') ?: null) : null,
                    'months' => array_fill(0, 12, 0),
                    'paid_dates' => array_fill(0, 12, null),
                    'total' => 0,
                    'excel_total' => null,
                    'issues' => [],
                ];
            }
            $row = &$rows[$byKey[$key]];

            if ($problem || ! $month) {
                $message = $problem ? 'Kiasi: '.$problem : 'Mwezi haujatambuliwa (safu '.$excelRow.').';
                $row['issues'][] = $message;
                $errors[] = ['row' => $excelRow, 'name' => $name, 'message' => $message];
                unset($row);

                continue;
            }
            $row['months'][$month - 1] += $amount;
            $row['total'] += $amount;
            $row['paid_dates'][$month - 1] ??= $date;
            unset($row);
        }

        return [$rows, $errors];
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
        $clean = str_ireplace([',', ' ', 'TZS', 'TSH', 'SH', '/=', '/-'], '', (string) $raw);
        if ($clean === '' || $clean === '-') {
            return [0, null];
        }
        if (is_numeric($clean)) {
            return [(int) round((float) $clean), null];
        }

        return [0, 'thamani "'.mb_substr((string) $raw, 0, 30).'" si namba'];
    }

    private function date(mixed $raw, mixed $text): ?string
    {
        try {
            if (is_numeric($raw) && $raw > 32874 && $raw < 73051) {
                return ExcelDate::excelToDateTimeObject((float) $raw)->format('Y-m-d');
            }
            $t = self::str($text ?? $raw);
            if ($t === '') {
                return null;
            }
            foreach (['d/m/Y', 'd-m-Y', 'd.m.Y', 'Y-m-d', 'd/m/y', 'm/d/Y'] as $format) {
                $d = \DateTime::createFromFormat('!'.$format, $t);
                if ($d && $d->format($format) === $t) {
                    return $d->format('Y-m-d');
                }
            }

            return Carbon::parse($t)->format('Y-m-d');
        } catch (Throwable) {
            return null;
        }
    }

    // --------------------------------------------------------- matching

    private static function tokenKey(string $key): string
    {
        $tokens = explode(' ', $key);
        sort($tokens);

        return implode(' ', $tokens);
    }

    /** Score 0-100 for how likely two names are the same person. */
    private static function similarity(string $a, string $b): int
    {
        similar_text($a, $b, $direct);
        similar_text(self::tokenKey($a), self::tokenKey($b), $sorted);
        $score = max($direct, $sorted);

        // "HADIJA OMARI" vs "HADIJA OMARI MOHAMEDI": all words of the shorter name present.
        $ta = array_unique(explode(' ', $a));
        $tb = array_unique(explode(' ', $b));
        $common = count(array_intersect($ta, $tb));
        if ($common >= 2 && $common === min(count($ta), count($tb))) {
            $score = max($score, 90);
        }

        return (int) round($score);
    }

    private function annotate(array $rows, int $year, array $errors): array
    {
        $teachers = Teacher::query()->get(['id', 'full_name', 'name_key']);
        $byKey = $teachers->keyBy('name_key');
        $byTokens = $teachers->keyBy(fn ($t) => self::tokenKey($t->name_key));
        $matrix = $this->ledger->monthMatrix($year);
        $duplicates = [];

        foreach ($rows as &$row) {
            $key = Teacher::keyFor($row['name']);
            $match = ['type' => 'new', 'teacher_id' => null, 'teacher_name' => null, 'score' => 0];

            if ($t = $byKey->get($key)) {
                $match = ['type' => 'exact', 'teacher_id' => $t->id, 'teacher_name' => $t->full_name, 'score' => 100];
            } elseif ($t = $byTokens->get(self::tokenKey($key))) {
                $match = ['type' => 'reordered', 'teacher_id' => $t->id, 'teacher_name' => $t->full_name, 'score' => 99];
            } else {
                $best = null;
                $bestScore = 0;
                foreach ($teachers as $t) {
                    $s = self::similarity($key, $t->name_key);
                    if ($s > $bestScore) {
                        [$best, $bestScore] = [$t, $s];
                    }
                }
                if ($best && $bestScore >= 80) {
                    $match = ['type' => 'suggested', 'teacher_id' => $best->id, 'teacher_name' => $best->full_name, 'score' => $bestScore];
                }
            }

            $row['match'] = $match;
            $row['action'] = match ($match['type']) {
                'exact', 'reordered' => 'existing',
                'suggested' => $match['score'] >= 90 ? 'existing' : 'new',
                default => 'new',
            };
            $row['teacher_id'] = $row['action'] === 'existing' ? $match['teacher_id'] : null;
            $row['is_new'] = $row['action'] === 'new';
            $row['duplicate_months'] = [];

            if ($row['teacher_id']) {
                foreach ($row['months'] as $i => $amount) {
                    $existing = $matrix[$row['teacher_id']][$i + 1] ?? 0;
                    if ($amount > 0 && $existing > 0) {
                        $row['duplicate_months'][] = $i + 1;
                        $duplicates[] = ['row' => $row['row'], 'name' => $row['name'], 'month' => $i + 1, 'existing_amount' => $existing, 'new_amount' => $amount];
                    }
                }
            }
        }
        unset($row);

        return [
            'teachers_count' => count($rows),
            'new_teachers' => count(array_filter($rows, fn ($r) => $r['action'] === 'new')),
            'suggestions' => count(array_filter($rows, fn ($r) => $r['match']['type'] === 'suggested')),
            'records_count' => array_sum(array_map(fn ($r) => count(array_filter($r['months'])), $rows)),
            'total_amount' => array_sum(array_column($rows, 'total')),
            'rows' => $rows,
            'errors' => $errors,
            'duplicates' => $duplicates,
        ];
    }

    private function emptyResult(string $message): array
    {
        return [
            'needs_mapping' => true,
            'message' => $message,
            'teachers_count' => 0,
            'new_teachers' => 0,
            'suggestions' => 0,
            'records_count' => 0,
            'total_amount' => 0,
            'rows' => [],
            'errors' => [],
            'duplicates' => [],
        ];
    }

    // ------------------------------------------------- teachers only

    /** Rows of a teacher list: name plus optional No., phone, email, notes. */
    private function readTeachers(array $table, array $map): array
    {
        $col = fn (string $role) => array_search($role, $map['columns'], true);
        $nameCol = $col('name');
        $rows = [];
        $errors = [];
        $byKey = [];

        foreach (array_slice($table['raw'], $map['header_row'], null, true) as $i => $raw) {
            $text = $table['text'][$i];
            $excelRow = $i + 1;
            $name = Teacher::cleanName((string) ($text[$nameCol] ?? ''));
            if ($name === '' || $this->isTotalRow($name)) {
                continue;
            }
            $key = Teacher::keyFor($name);
            if (isset($byKey[$key])) {
                $errors[] = ['row' => $excelRow, 'name' => $name, 'message' => 'Jina limejirudia (safu '.$rows[$byKey[$key]]['row'].'); safu hii imerukwa.'];

                continue;
            }

            $phone = ($pc = $col('phone')) !== false ? (self::str($text[$pc] ?? '') ?: null) : null;
            $email = ($ec = $col('email')) !== false ? (self::str($text[$ec] ?? '') ?: null) : null;
            $issues = [];
            if ($phone !== null && ! preg_match('/^[0-9+ ()\-]{9,20}$/', $phone)) {
                $issues[] = 'Namba ya simu "'.$phone.'" si sahihi; haitahifadhiwa.';
                $phone = null;
            }
            if ($email !== null && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $issues[] = 'Barua pepe "'.$email.'" si sahihi; haitahifadhiwa.';
                $email = null;
            }

            $byKey[$key] = count($rows);
            $rows[] = [
                'row' => $excelRow,
                'number' => ($nc = $col('number')) !== false && is_numeric($raw[$nc] ?? null) ? (int) $raw[$nc] : null,
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'notes' => ($oc = $col('notes')) !== false ? (self::str($text[$oc] ?? '') ?: null) : null,
                'issues' => $issues,
            ];
            foreach ($issues as $issue) {
                $errors[] = ['row' => $excelRow, 'name' => $name, 'message' => $issue];
            }
        }

        return [$rows, $errors];
    }

    private function annotateTeachers(array $rows, array $errors): array
    {
        $teachers = Teacher::query()->get(['id', 'full_name', 'name_key', 'phone', 'email', 'number']);
        $byKey = $teachers->keyBy('name_key');
        $byTokens = $teachers->keyBy(fn ($t) => self::tokenKey($t->name_key));

        foreach ($rows as &$row) {
            $key = Teacher::keyFor($row['name']);
            $match = ['type' => 'new', 'teacher_id' => null, 'teacher_name' => null, 'score' => 0];
            if ($t = $byKey->get($key)) {
                $match = ['type' => 'exact', 'teacher_id' => $t->id, 'teacher_name' => $t->full_name, 'score' => 100];
            } elseif ($t = $byTokens->get(self::tokenKey($key))) {
                $match = ['type' => 'reordered', 'teacher_id' => $t->id, 'teacher_name' => $t->full_name, 'score' => 99];
            } else {
                $best = null;
                $bestScore = 0;
                foreach ($teachers as $t) {
                    $sc = self::similarity($key, $t->name_key);
                    if ($sc > $bestScore) {
                        [$best, $bestScore] = [$t, $sc];
                    }
                }
                if ($best && $bestScore >= 80) {
                    $match = ['type' => 'suggested', 'teacher_id' => $best->id, 'teacher_name' => $best->full_name, 'score' => $bestScore];
                }
            }

            $row['match'] = $match;
            $row['action'] = match ($match['type']) {
                'exact', 'reordered' => 'existing',
                'suggested' => $match['score'] >= 90 ? 'existing' : 'new',
                default => 'new',
            };
            $row['teacher_id'] = $row['action'] === 'existing' ? $match['teacher_id'] : null;
            $row['is_new'] = $row['action'] === 'new';

            // What an update of an existing teacher would change.
            $row['changes'] = [];
            if ($row['teacher_id'] && ($t = $teachers->firstWhere('id', $row['teacher_id']))) {
                foreach (['phone', 'email', 'number'] as $field) {
                    if ($row[$field] !== null && (string) $row[$field] !== (string) $t->{$field}) {
                        $row['changes'][] = $field;
                    }
                }
            }
            // Same shape as contribution rows so clients can share one model.
            $row['months'] = array_fill(0, 12, 0);
            $row['paid_dates'] = array_fill(0, 12, null);
            $row['total'] = 0;
            $row['excel_total'] = null;
            $row['duplicate_months'] = [];
        }
        unset($row);

        return [
            'teachers_count' => count($rows),
            'new_teachers' => count(array_filter($rows, fn ($r) => $r['action'] === 'new')),
            'updated_teachers' => count(array_filter($rows, fn ($r) => $r['action'] === 'existing' && $r['changes'])),
            'suggestions' => count(array_filter($rows, fn ($r) => $r['match']['type'] === 'suggested')),
            'records_count' => 0,
            'total_amount' => 0,
            'rows' => $rows,
            'errors' => $errors,
            'duplicates' => [],
        ];
    }

    /** Creates new teachers and updates contact details of existing ones. */
    public function commitTeachers(array $rows): array
    {
        $stats = ['teachers_created' => 0, 'teachers_updated' => 0, 'teachers_unchanged' => 0, 'rows_skipped' => 0];

        DB::transaction(function () use ($rows, &$stats) {
            foreach ($rows as $row) {
                $action = $row['action'] ?? (! empty($row['teacher_id']) ? 'existing' : 'new');
                if ($action === 'skip') {
                    $stats['rows_skipped']++;

                    continue;
                }
                $name = Teacher::cleanName($row['name']);
                $teacher = $action === 'existing' && ! empty($row['teacher_id']) ? Teacher::withTrashed()->find($row['teacher_id']) : null;
                $teacher ??= Teacher::withTrashed()->firstWhere('name_key', Teacher::keyFor($name));

                $values = array_filter([
                    'phone' => $row['phone'] ?? null,
                    'email' => $row['email'] ?? null,
                    'number' => $row['number'] ?? null,
                    'notes' => $row['notes'] ?? null,
                ], fn ($v) => $v !== null && $v !== '');

                if (! $teacher) {
                    Teacher::create($values + [
                        'full_name' => $name,
                        'number' => $values['number'] ?? ((int) Teacher::withTrashed()->max('number') + 1),
                    ]);
                    $stats['teachers_created']++;

                    continue;
                }
                if ($teacher->trashed()) {
                    $teacher->restore();
                }
                $teacher->fill($values);
                if ($teacher->isDirty()) {
                    $teacher->save();
                    $stats['teachers_updated']++;
                } else {
                    $stats['teachers_unchanged']++;
                }
            }
        });

        return $stats;
    }

    // ----------------------------------------------------------- commit

    /**
     * @param  array<int, array{name: string, number?: int|null, months: array<int, int>, paid_dates?: array, teacher_id?: int|null, action?: string, phone?: string|null, email?: string|null}>  $rows
     * @param  string  $duplicateMode  skip|replace
     */
    public function commit(array $rows, int $year, string $duplicateMode, ?int $userId): array
    {
        $stats = ['teachers_created' => 0, 'records_created' => 0, 'records_skipped' => 0, 'records_replaced' => 0, 'rows_skipped' => 0, 'amount' => 0];

        DB::transaction(function () use ($rows, $year, $duplicateMode, $userId, &$stats) {
            $matrix = $this->ledger->monthMatrix($year);

            foreach ($rows as $row) {
                $action = $row['action'] ?? (! empty($row['teacher_id']) ? 'existing' : 'new');
                if ($action === 'skip') {
                    $stats['rows_skipped']++;

                    continue;
                }

                $name = Teacher::cleanName($row['name']);
                $teacher = $action === 'existing' && ! empty($row['teacher_id']) ? Teacher::withTrashed()->find($row['teacher_id']) : null;
                $teacher ??= Teacher::withTrashed()->firstWhere('name_key', Teacher::keyFor($name));
                if ($teacher?->trashed()) {
                    $teacher->restore();
                }
                if (! $teacher) {
                    $teacher = Teacher::create([
                        'full_name' => $name,
                        'number' => $row['number'] ?? ((int) Teacher::withTrashed()->max('number') + 1),
                        'phone' => $row['phone'] ?? null,
                        'email' => $row['email'] ?? null,
                    ]);
                    $stats['teachers_created']++;
                } else {
                    // Fill contact details the system does not have yet.
                    $teacher->fill(array_filter([
                        'phone' => $teacher->phone ? null : ($row['phone'] ?? null),
                        'email' => $teacher->email ? null : ($row['email'] ?? null),
                    ]));
                    if ($teacher->isDirty()) {
                        $teacher->save();
                    }
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
                        'paid_at' => $row['paid_dates'][$i] ?? null,
                        'reference' => sprintf('IMP-%d-%04d-%02d', $year, $teacher->id, $month),
                        'notes' => 'Imeingizwa kutoka Excel',
                        'source' => 'import',
                        'recorded_by' => $userId,
                    ]);
                    $matrix[$teacher->id][$month] = $amount;
                    $stats['records_created']++;
                    $stats['amount'] += $amount;
                }
            }
        });

        return $stats;
    }
}
