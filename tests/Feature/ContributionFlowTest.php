<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\NotificationLog;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ContributionFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-09-30 10:00:00');
        $this->admin = User::create(['name' => 'Mhazini', 'email' => 'admin@test.local', 'password' => 'secret-123', 'role' => 'admin']);
        $this->actingAs($this->admin, 'sanctum');
    }

    private function teacher(array $attrs = []): Teacher
    {
        return Teacher::create($attrs + ['full_name' => 'ALICIA  JOACHIM KIMATI', 'number' => 1, 'phone' => '0712 345 678', 'email' => 'alicia@test.local']);
    }

    private function record(Teacher $teacher, int $month, int $amount = 10000, array $extra = [])
    {
        return $this->postJson('/api/contributions', $extra + [
            'teacher_id' => $teacher->id, 'year' => 2026, 'month' => $month,
            'amount' => $amount, 'paid_at' => "2026-0{$month}-15",
        ]);
    }

    public function test_login_returns_token(): void
    {
        $this->postJson('/api/login', ['email' => 'admin@test.local', 'password' => 'secret-123'])
            ->assertOk()->assertJsonStructure(['token', 'user' => ['name', 'role']]);
        $this->postJson('/api/login', ['email' => 'admin@test.local', 'password' => 'wrong'])->assertStatus(422);
    }

    public function test_recording_contribution_updates_all_totals_and_notifies_teacher(): void
    {
        Mail::fake();
        $teacher = $this->teacher();
        $this->assertSame('ALICIA JOACHIM KIMATI', $teacher->full_name);

        $response = $this->record($teacher, 1)->assertCreated();
        $response->assertJsonPath('teacher.total', 10000)
            ->assertJsonPath('teacher.months.0', 10000)
            ->assertJsonPath('contribution.reference', 'UST-2026-000001')
            ->assertJsonPath('contribution.recorded_by', 'Mhazini')
            ->assertJsonPath('channels', ['sms', 'email']);

        $this->record($teacher, 2)->assertCreated();
        $this->record($teacher, 3)->assertCreated()->assertJsonPath('teacher.total', 30000)
            ->assertJsonPath('teacher.status', 'sehemu')
            ->assertJsonPath('teacher.months_paid', 3);

        $dashboard = $this->getJson('/api/dashboard?year=2026')->assertOk();
        $dashboard->assertJsonPath('total_contributions', 30000)
            ->assertJsonPath('total_teachers', 1)
            ->assertJsonPath('month', 9)
            ->assertJsonPath('monthly.0.total', 10000)
            ->assertJsonPath('monthly.0.paid_count', 1)
            ->assertJsonCount(3, 'recent');

        $this->getJson('/api/contributions?year=2026')->assertJsonPath('meta.total', 3)->assertJsonPath('meta.sum', 30000);

        // Notifications ran after the response: one SMS (log driver) and one email per payment.
        // SMS_DRIVER=log in tests: attempted, but honestly reported as not delivered.
        $this->assertSame(3, NotificationLog::where('channel', 'sms')->where('status', 'skipped')->where('error', 'like', '%SMS_DRIVER=log%')->count());
        $this->assertSame('255712345678', NotificationLog::where('channel', 'sms')->first()->recipient);
        $this->assertStringContainsString('TZS 10,000 kwa mwezi Machi 2026', NotificationLog::latest('id')->first()->message);
        $this->assertStringContainsString('Jumla ya michango yako 2026: TZS 30,000', NotificationLog::latest('id')->first()->message);
        Mail::assertSent(\App\Mail\ContributionReceived::class, 3);
    }

    public function test_duplicate_month_requires_explicit_additional_mode(): void
    {
        $teacher = $this->teacher();
        $this->record($teacher, 4)->assertCreated();

        $this->record($teacher, 4, 5000)->assertStatus(409)->assertJsonPath('code', 'duplicate')->assertJsonCount(1, 'existing');
        $this->record($teacher, 4, 5000, ['mode' => 'additional'])->assertCreated()->assertJsonPath('teacher.months.3', 15000);
    }

    public function test_notification_is_skipped_when_teacher_has_no_contacts(): void
    {
        $teacher = $this->teacher(['phone' => null, 'email' => null]);
        $this->record($teacher, 1)->assertCreated()->assertJsonPath('channels', []);
        $this->assertSame(2, NotificationLog::where('status', 'skipped')->count());
    }

    public function test_edit_and_delete_recalculate_totals(): void
    {
        $teacher = $this->teacher();
        $id = $this->record($teacher, 1)->json('contribution.id');

        $this->putJson("/api/contributions/{$id}", [
            'teacher_id' => $teacher->id, 'year' => 2026, 'month' => 1, 'amount' => 7000, 'paid_at' => '2026-01-20', 'notify' => false,
        ])->assertOk()->assertJsonPath('teacher.total', 7000)->assertJsonPath('teacher.monthly.0.status', 'sehemu');

        $this->deleteJson("/api/contributions/{$id}")->assertOk()->assertJsonPath('teacher.total', 0);
        $this->assertSoftDeleted(Contribution::class, ['id' => $id]);
    }

    public function test_teacher_list_filters_and_sorts(): void
    {
        $a = $this->teacher();
        $this->teacher(['full_name' => 'ZAMZAM ISSA MUSSA', 'number' => 2, 'phone' => null, 'email' => null]);
        $this->record($a, 9)->assertCreated();

        $this->getJson('/api/teachers?year=2026&month=9&status=amelipa')->assertJsonCount(1, 'data')->assertJsonPath('data.0.full_name', 'ALICIA JOACHIM KIMATI');
        $this->getJson('/api/teachers?year=2026&search=zamzam')->assertJsonCount(1, 'data');
        $this->getJson('/api/teachers?year=2026&sort=total_desc')->assertJsonPath('data.0.total', 10000);
        $this->getJson('/api/teachers?year=2026')->assertJsonPath('totals.months.8', 10000)->assertJsonPath('totals.unpaid', 1);
    }

    public function test_excel_import_preview_and_duplicate_handling(): void
    {
        $existing = $this->teacher();
        $this->record($existing, 1, 10000, ['notify' => false])->assertCreated();

        $sheet = (new Spreadsheet)->getActiveSheet()->setTitle('2026');
        $sheet->fromArray([
            [null, 'BEJAMIN WILLIAM MKAPA SEKONDARI'],
            [null, 'MICHANGO YA USTAWI WA JAMII 2026'],
            ['No.', 'JINA LA MWALIMU', 'JAN', 'FEB', 'MAR', 'APRIL', 'MAY', 'JUNE', 'JULY', 'AUG', 'SEPT', 'OCT', 'NOV', 'DEC', 'TOTAL'],
            [1, 'ALICIA JOACHIM  KIMATI', 10000, 10000, 10000, null, null, null, null, null, null, null, null, null, 30000],
            [2, 'HATIBA    SELEMANI    MSUYA', null, 'abc', null, null, null, null, null, null, null, null, null, null, 0],
        ]);
        $path = tempnam(sys_get_temp_dir(), 'imp').'.xlsx';
        (new Xlsx($sheet->getParent()))->save($path);

        $preview = $this->post('/api/import/preview', ['file' => new UploadedFile($path, 'michango.xlsx', null, null, true)], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('year', 2026)
            ->assertJsonPath('teachers_count', 2)
            ->assertJsonPath('new_teachers', 1)
            ->assertJsonPath('records_count', 3)
            ->assertJsonPath('rows.1.name', 'HATIBA SELEMANI MSUYA')
            ->assertJsonCount(1, 'duplicates')
            ->assertJsonCount(1, 'errors')
            ->json();

        $this->postJson('/api/import/commit', ['year' => 2026, 'duplicate_mode' => 'skip', 'rows' => $preview['rows']])
            ->assertOk()
            ->assertJsonPath('teachers_created', 1)
            ->assertJsonPath('records_created', 2)
            ->assertJsonPath('records_skipped', 1);

        $this->getJson("/api/teachers/{$existing->id}?year=2026")->assertJsonPath('total', 30000);
    }

    public function test_settings_update_changes_monthly_rate(): void
    {
        $this->putJson('/api/settings', ['monthly_amount' => 5000, 'sms_enabled' => false])->assertOk()
            ->assertJsonPath('monthly_amount', 5000)->assertJsonPath('sms_enabled', false);

        $teacher = $this->teacher();
        $this->record($teacher, 1, 5000)->assertCreated()->assertJsonPath('teacher.monthly.0.status', 'amelipa')->assertJsonPath('channels', ['email']);
    }
}
