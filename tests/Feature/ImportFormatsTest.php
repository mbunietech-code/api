<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

/** The importer must cope with spreadsheets that differ from the school's template. */
class ImportFormatsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::create(['name' => 'Mhazini', 'email' => 'a@test.local', 'password' => 'secret-123']), 'sanctum');
    }

    /** @param array<string, array<int, array>> $sheets sheet title => rows */
    private function file(array $sheets, string $name = 'michango.xlsx'): UploadedFile
    {
        $book = new Spreadsheet;
        $book->removeSheetByIndex(0);
        foreach ($sheets as $title => $rows) {
            $book->createSheet()->setTitle($title)->fromArray($rows, null, 'A1', true);
        }
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($book))->save($path);

        return new UploadedFile($path, $name, null, null, true);
    }

    private function preview(UploadedFile $file, array $extra = [])
    {
        return $this->post('/api/import/preview', ['file' => $file] + $extra, ['Accept' => 'application/json']);
    }

    public function test_misspelt_headers_and_swahili_months_are_understood(): void
    {
        $file = $this->file(['Sheet1' => [
            [1, 'BEJAMIN WILLIAM MKAPA SEKONDARI'],
            [null, 'MICHANG0 YA USTAWI WA JAMII 2026'],
            ['No.', 'JILA LA MWALIMU', 'Januari 2026', 'FEB', 'MACHI', 'Aprili', 'JUNE', 'SEPT.', 'TOTAL'],
            [1, ' JOSEPH DEO  LUGAYILA', 10000, '10,000', 'TSH 10000', null, null, null, null],
            [2, 'OMEGA', null, null, null, 10000, 10000, '10000/=', null],
            [null, 'JUMLA', 10000, 10000, 10000, 10000, 10000, 10000, 60000],
        ]]);

        $this->preview($file)->assertOk()
            ->assertJsonPath('needs_mapping', false)
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('header_row', 3)
            ->assertJsonPath('mapping.columns.B', 'name')
            ->assertJsonPath('mapping.columns.F', 'month:4')
            ->assertJsonPath('mapping.columns.G', 'month:6')
            ->assertJsonPath('mapping.columns.H', 'month:9')
            ->assertJsonPath('mapping.columns.I', 'total')
            ->assertJsonPath('teachers_count', 2)
            ->assertJsonPath('rows.0.name', 'JOSEPH DEO LUGAYILA')
            ->assertJsonPath('rows.0.months', [10000, 10000, 10000, 0, 0, 0, 0, 0, 0, 0, 0, 0])
            ->assertJsonPath('rows.1.months', [0, 0, 0, 10000, 0, 10000, 0, 0, 10000, 0, 0, 0])
            ->assertJsonPath('records_count', 6);
    }

    public function test_payment_list_is_grouped_per_teacher_and_month(): void
    {
        $file = $this->file(['Malipo' => [
            ['Tarehe', 'Jina la Mwalimu', 'Kiasi (TSH)', 'Kumbukumbu'],
            [ExcelDate::formattedPHPToExcel(2025, 3, 15), 'Grace Temu', 10000, 'R-1'],
            ['16/04/2025', 'Grace Temu', 10000, 'R-2'],
            ['20/04/2025', 'Grace Temu', 5000, 'R-3'],
            ['02/03/2025', 'Paul Malenja', '10,000', 'R-4'],
        ]]);

        $preview = $this->preview($file)->assertOk()
            ->assertJsonPath('layout', 'long')
            ->assertJsonPath('year', 2025)
            ->assertJsonPath('teachers_count', 2)
            ->assertJsonPath('rows.0.months.2', 10000)
            ->assertJsonPath('rows.0.months.3', 15000)
            ->assertJsonPath('rows.0.paid_dates.2', '2025-03-15')
            ->assertJsonPath('rows.1.months.2', 10000)
            ->json();

        $this->postJson('/api/import/commit', ['year' => $preview['year'], 'duplicate_mode' => 'skip', 'rows' => $preview['rows']])
            ->assertOk()->assertJsonPath('records_created', 3)->assertJsonPath('teachers_created', 2);
        $this->assertSame('2025-03-15', Contribution::where('month', 3)->where('amount', 10000)->first()->paid_at->format('Y-m-d'));
    }

    public function test_file_without_headers_asks_for_mapping_then_uses_it(): void
    {
        $file = $this->file(['Sheet1' => [
            ['Alicia Joachim Kimati', 10000, 10000],
            ['Zamzam Issa Mussa', 10000, null],
        ]]);

        $this->preview($file)->assertOk()
            ->assertJsonPath('needs_mapping', true)
            ->assertJsonPath('mapping.columns.A', 'name')
            ->assertJsonCount(3, 'columns');

        $file = $this->file(['Sheet1' => [
            ['Alicia Joachim Kimati', 10000, 10000],
            ['Zamzam Issa Mussa', 10000, null],
        ]]);
        $this->preview($file, [
            'year' => 2026,
            'mapping' => json_encode(['layout' => 'wide', 'header_row' => 0, 'columns' => ['A' => 'name', 'B' => 'month:7', 'C' => 'month:8']]),
        ])->assertOk()
            ->assertJsonPath('needs_mapping', false)
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('teachers_count', 2)
            ->assertJsonPath('rows.0.months.6', 10000)
            ->assertJsonPath('rows.0.months.7', 10000)
            ->assertJsonPath('records_count', 3);
    }

    public function test_best_sheet_is_chosen_and_sheet_can_be_selected(): void
    {
        $sheets = [
            'Maelezo' => [['Faili hili lina michango ya mwaka 2026']],
            'Data' => [['NA', 'MAJINA', 'JAN', 'FEB'], [1, 'Daniel Nade', 10000, 10000]],
        ];
        $this->preview($this->file($sheets))->assertOk()
            ->assertJsonPath('sheet', 'Data')
            ->assertJsonPath('sheets', ['Maelezo', 'Data'])
            ->assertJsonPath('teachers_count', 1);

        $this->preview($this->file($sheets), ['sheet' => 'Maelezo'])->assertOk()->assertJsonPath('needs_mapping', true);
    }

    public function test_names_are_matched_exactly_reordered_or_suggested(): void
    {
        $alicia = Teacher::create(['full_name' => 'ALICIA JOACHIM KIMATI']);
        $willy = Teacher::create(['full_name' => 'WILLY SAMSON']);
        $hadija = Teacher::create(['full_name' => 'HADIJA OMARI MOHAMEDI']);

        $preview = $this->preview($this->file(['S' => [
            ['JINA', 'JAN'],
            ['Kimati Alicia Joachim', 10000],
            ['Weillad Samson', 10000],
            ['Hadija Omari', 10000],
            ['Mwalimu Mpya Kabisa', 10000],
        ]], 'michango 2026.xlsx'))->assertOk()
            ->assertJsonPath('rows.0.match.type', 'reordered')
            ->assertJsonPath('rows.0.teacher_id', $alicia->id)
            ->assertJsonPath('rows.1.match.type', 'suggested')
            ->assertJsonPath('rows.1.match.teacher_id', $willy->id)
            ->assertJsonPath('rows.2.match.type', 'suggested')
            ->assertJsonPath('rows.2.action', 'existing')
            ->assertJsonPath('rows.2.teacher_id', $hadija->id)
            ->assertJsonPath('rows.3.match.type', 'new')
            ->json();

        // The user confirms Weillad = Willy and decides to skip the new name.
        $rows = $preview['rows'];
        $rows[1]['action'] = 'existing';
        $rows[1]['teacher_id'] = $willy->id;
        $rows[3]['action'] = 'skip';

        $this->postJson('/api/import/commit', ['year' => 2026, 'duplicate_mode' => 'skip', 'rows' => $rows])
            ->assertOk()
            ->assertJsonPath('teachers_created', 0)
            ->assertJsonPath('rows_skipped', 1)
            ->assertJsonPath('records_created', 3);

        $this->assertSame(3, Teacher::count());
        $this->assertSame(10000, (int) Contribution::where('teacher_id', $willy->id)->sum('amount'));
    }
}
