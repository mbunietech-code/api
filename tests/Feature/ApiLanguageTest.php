<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApiLanguageTest extends TestCase
{
    use RefreshDatabase;

    public function test_messages_follow_accept_language(): void
    {
        User::create(['name' => 'A', 'email' => 'a@test.local', 'password' => 'secret-123']);
        $body = ['email' => 'a@test.local', 'password' => 'wrong'];

        $this->postJson('/api/login', $body, ['Accept-Language' => 'sw'])
            ->assertStatus(422)->assertJsonPath('message', 'Barua pepe au nenosiri si sahihi.');

        $this->postJson('/api/login', $body, ['Accept-Language' => 'en'])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Incorrect email or password.')
            ->assertJsonPath('errors.email.0', 'Incorrect email or password.');
    }

    public function test_composed_import_messages_are_translated(): void
    {
        $this->assertSame('Month not recognised (row 12).', \App\Http\Middleware\ApiLanguage::english('Mwezi haujatambuliwa (safu 12).'));
        $this->assertSame('MAY: value "abc" is not a number', \App\Http\Middleware\ApiLanguage::english('MEI: thamani "abc" si namba'));
        $this->assertSame('Excel total (30,000) does not match the sum of the months (20,000).', \App\Http\Middleware\ApiLanguage::english('Jumla ya Excel (30,000) hailingani na jumla ya miezi (20,000).'));
    }

    /** English clients must still get JSON objects, not arrays, for empty maps (sync relies on it). */
    public function test_english_responses_keep_empty_objects(): void
    {
        $this->actingAs(User::create(['name' => 'A', 'email' => 'a@test.local', 'password' => 'secret-123']), 'sanctum');
        $teacher = (string) \Illuminate\Support\Str::uuid();

        $response = $this->postJson('/api/sync/push', [
            'teachers' => [['uuid' => $teacher, 'full_name' => 'ASHA JUMA', 'deleted' => false]],
            'contributions' => [],
        ], ['Accept-Language' => 'en'])->assertOk();

        $this->assertStringContainsString('"remap":{}', $response->getContent());
        $this->assertStringContainsString('"errors":{}', $response->getContent());
        $this->assertStringContainsString('"ids":{}', $response->getContent());
    }
}
