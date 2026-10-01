<?php

namespace Tests\Feature;

use App\Models\Contribution;
use App\Models\NotificationLog;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class SyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        Carbon::setTestNow('2026-10-01 09:00:00');
        $this->actingAs(User::create(['name' => 'Mhazini', 'email' => 'a@test.local', 'password' => 'secret-123']), 'sanctum');
    }

    public function test_full_pull_returns_everything_with_uuids(): void
    {
        $t = Teacher::create(['full_name' => 'ALICIA JOACHIM KIMATI', 'number' => 1]);
        Contribution::create(['teacher_id' => $t->id, 'year' => 2026, 'month' => 1, 'amount' => 10000, 'reference' => 'X-1']);

        $this->getJson('/api/sync/pull')->assertOk()
            ->assertJsonPath('full', true)
            ->assertJsonPath('teachers.0.uuid', $t->uuid)
            ->assertJsonPath('contributions.0.teacher_uuid', $t->uuid)
            ->assertJsonPath('contributions.0.amount', 10000)
            ->assertJsonPath('settings.monthly_amount', 10000);
    }

    public function test_offline_records_are_pushed_and_teacher_is_notified(): void
    {
        $teacherUuid = (string) Str::uuid();
        $contribUuid = (string) Str::uuid();

        $this->postJson('/api/sync/push', [
            'teachers' => [['uuid' => $teacherUuid, 'full_name' => 'ZAMZAM ISSA MUSSA', 'phone' => '0712000000', 'is_active' => true, 'deleted' => false]],
            'contributions' => [[
                'uuid' => $contribUuid, 'teacher_uuid' => $teacherUuid, 'year' => 2026, 'month' => 9, 'amount' => 10000,
                'paid_at' => '2026-09-30', 'reference' => 'UST-2026-000050', 'created_at' => '2026-09-30T08:00:00Z', 'deleted' => false, 'notify' => true,
            ]],
        ])->assertOk()
            ->assertJsonPath('teachers.accepted.0', $teacherUuid)
            ->assertJsonPath('contributions.accepted.0', $contribUuid);

        $c = Contribution::firstWhere('uuid', $contribUuid);
        $this->assertSame('ZAMZAM ISSA MUSSA', $c->teacher->full_name);
        $this->assertSame(10000, $c->amount);
        $this->assertSame(1, NotificationLog::where('contribution_id', $c->id)->where('channel', 'sms')->count());

        // Pushing the same record again (e.g. a retried sync) is idempotent and does not notify twice.
        $this->postJson('/api/sync/push', ['contributions' => [[
            'uuid' => $contribUuid, 'teacher_uuid' => $teacherUuid, 'year' => 2026, 'month' => 9, 'amount' => 10000, 'deleted' => false, 'notify' => false,
        ]]])->assertOk();
        $this->assertSame(1, Contribution::count());
        $this->assertSame(1, NotificationLog::where('channel', 'sms')->count());
    }

    public function test_teacher_created_offline_with_existing_name_is_merged(): void
    {
        $server = Teacher::create(['full_name' => 'ALICIA JOACHIM KIMATI', 'number' => 2]);
        $offline = (string) Str::uuid();
        $contrib = (string) Str::uuid();

        $this->postJson('/api/sync/push', [
            'teachers' => [['uuid' => $offline, 'full_name' => 'Alicia  Joachim Kimati', 'email' => 'alicia@test.local', 'deleted' => false]],
            'contributions' => [['uuid' => $contrib, 'teacher_uuid' => $offline, 'year' => 2026, 'month' => 4, 'amount' => 10000, 'deleted' => false]],
        ])->assertOk()->assertJsonPath("teachers.remap.$offline", $server->uuid);

        $this->assertSame(1, Teacher::count());
        $this->assertSame('alicia@test.local', $server->fresh()->email);
        $this->assertSame($server->id, Contribution::firstWhere('uuid', $contrib)->teacher_id);
    }

    public function test_deletes_sync_both_ways_and_incremental_pull(): void
    {
        $t = Teacher::create(['full_name' => 'DANIEL NADE']);
        $c = Contribution::create(['teacher_id' => $t->id, 'year' => 2026, 'month' => 2, 'amount' => 10000, 'reference' => 'X-2']);
        Carbon::setTestNow('2026-10-01 09:05:00');
        $since = $this->getJson('/api/sync/pull')->json('server_time');

        Carbon::setTestNow('2026-10-01 10:00:00');
        $this->postJson('/api/sync/push', ['contributions' => [[
            'uuid' => $c->uuid, 'teacher_uuid' => $t->uuid, 'year' => 2026, 'month' => 2, 'amount' => 10000, 'deleted' => true,
        ]]])->assertOk();
        $this->assertSoftDeleted($c);

        $pull = $this->getJson('/api/sync/pull?since='.urlencode($since))->assertOk()->assertJsonPath('full', false);
        $this->assertCount(1, $pull->json('contributions'));
        $this->assertTrue($pull->json('contributions.0.deleted'));
        $this->assertCount(0, $pull->json('teachers'));
    }

    public function test_contribution_for_unknown_teacher_is_reported(): void
    {
        $uuid = (string) Str::uuid();
        $this->postJson('/api/sync/push', ['contributions' => [[
            'uuid' => $uuid, 'teacher_uuid' => (string) Str::uuid(), 'year' => 2026, 'month' => 1, 'amount' => 10000, 'deleted' => false,
        ]]], ['Accept-Language' => 'sw'])->assertOk()->assertJsonPath("contributions.errors.$uuid", 'Mwalimu wa mchango huu hajapatikana kwenye seva.');
    }
}
