<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RetrieveCallRecordingJob;
use App\Models\CallRecord;
use App\Models\ProductionRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CallRecordingTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): array
    {
        $admin = User::create([
            'name' => 'Admin Audio',
            'email' => 'audio@test.ma',
            'password' => 'motdepasse1',
            'role' => 'admin',
            'active' => true,
        ]);

        return [$admin, $admin->createToken('session')->plainTextToken];
    }

    private function headers(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function makeCall(array $attributes = []): CallRecord
    {
        return CallRecord::create(array_merge([
            'provider' => 'issabel',
            'provider_call_id' => 'audio-cdr-1',
            'recording_state' => CallRecord::RECORDING_STORED,
            'recording_disk' => 'local',
            'recording_path' => 'recordings/issabel/audio.wav',
            'recording_mime' => 'audio/wav',
            'recording_size' => 10,
        ], $attributes));
    }

    public function test_authorized_admin_can_download_a_private_recording(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('recordings/issabel/audio.wav', 'audio-data');
        [, $token] = $this->admin();
        $call = $this->makeCall();

        $response = $this->get("/api/calls/{$call->id}/recording", $this->headers($token));

        $response->assertOk();
        $this->assertSame('audio-data', $response->streamedContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $response->headers->get('Cache-Control'));
    }

    public function test_unauthorized_or_non_admin_users_cannot_access_recordings(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('recordings/issabel/audio.wav', 'audio-data');
        $call = $this->makeCall();

        $this->getJson("/api/calls/{$call->id}/recording")->assertUnauthorized();

        $agent = User::create([
            'name' => 'Agent Audio',
            'email' => 'agent-audio@test.ma',
            'password' => 'motdepasse1',
            'role' => 'agent',
            'active' => true,
        ]);

        $this->getJson(
            "/api/calls/{$call->id}/recording",
            $this->headers($agent->createToken('session')->plainTextToken),
        )->assertForbidden();
    }

    public function test_unavailable_recording_returns_a_distinct_state(): void
    {
        [, $token] = $this->admin();
        $call = $this->makeCall([
            'recording_state' => CallRecord::RECORDING_UNAVAILABLE,
            'recording_disk' => null,
            'recording_path' => null,
            'recording_mime' => null,
            'recording_size' => null,
        ]);

        $this->getJson("/api/calls/{$call->id}/recording", $this->headers($token))
            ->assertNotFound()
            ->assertJsonPath('state', CallRecord::RECORDING_UNAVAILABLE);
    }

    public function test_unsafe_stored_path_is_never_used(): void
    {
        Storage::fake('local');
        [, $token] = $this->admin();
        $call = $this->makeCall(['recording_path' => '../secret.wav']);

        $this->getJson("/api/calls/{$call->id}/recording", $this->headers($token))
            ->assertNotFound();
    }

    public function test_missing_private_file_is_reported_and_state_is_updated(): void
    {
        Storage::fake('local');
        [, $token] = $this->admin();
        $call = $this->makeCall();

        $this->getJson("/api/calls/{$call->id}/recording", $this->headers($token))
            ->assertStatus(410)
            ->assertJsonPath('state', CallRecord::RECORDING_MISSING);

        $this->assertSame(CallRecord::RECORDING_MISSING, $call->refresh()->recording_state);
    }

    public function test_retrieval_endpoint_keeps_the_final_state_with_a_synchronous_queue(): void
    {
        [, $token] = $this->admin();
        config(['telephony.issabel.recording_root' => '']);

        $call = $this->makeCall([
            'recording_state' => CallRecord::RECORDING_REFERENCE_ONLY,
            'recording_disk' => null,
            'recording_path' => null,
            'recording_provider_reference' => 'rec.wav',
        ]);

        $this->postJson("/api/calls/{$call->id}/recording/retrieve", [], $this->headers($token))
            ->assertStatus(202)
            ->assertJsonPath('data.state', CallRecord::RECORDING_UNSUPPORTED);

        $this->assertSame(CallRecord::RECORDING_UNSUPPORTED, $call->refresh()->recording_state);
    }

    public function test_recording_stream_supports_range_requests(): void
    {
        Storage::fake('local');
        $audio = 'RIFF'.pack('V', 100).'WAVE'.str_repeat("\0", 100);
        Storage::disk('local')->put('recordings/issabel/audio.wav', $audio);
        [, $token] = $this->admin();
        $call = $this->makeCall();

        $response = $this->get("/api/calls/{$call->id}/recording/stream", array_merge(
            $this->headers($token),
            ['Range' => 'bytes=0-9'],
        ));

        $response->assertStatus(206);
        $this->assertSame('bytes 0-9/'.strlen($audio), $response->headers->get('Content-Range'));
        $this->assertSame('bytes', $response->headers->get('Accept-Ranges'));
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(substr($audio, 0, 10), $response->streamedContent());
    }

    public function test_recording_stream_returns_full_content_without_range(): void
    {
        Storage::fake('local');
        $audio = 'RIFF'.pack('V', 64).'WAVE'.str_repeat("\0", 64);
        Storage::disk('local')->put('recordings/issabel/audio.wav', $audio);
        [, $token] = $this->admin();
        $call = $this->makeCall();

        $response = $this->get("/api/calls/{$call->id}/recording/stream", $this->headers($token));

        $response->assertOk();
        $this->assertSame((string) strlen($audio), $response->headers->get('Content-Length'));
        $this->assertSame($audio, $response->streamedContent());
    }

    public function test_recording_stream_is_private(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('recordings/issabel/audio.wav', 'audio-data');
        $call = $this->makeCall();

        $this->getJson("/api/calls/{$call->id}/recording/stream")->assertUnauthorized();
    }

    public function test_retrieval_without_a_provider_reference_is_refused(): void
    {
        Queue::fake();
        [, $token] = $this->admin();
        $call = $this->makeCall([
            'recording_state' => CallRecord::RECORDING_UNAVAILABLE,
            'recording_disk' => null,
            'recording_path' => null,
            'recording_provider_reference' => null,
        ]);

        $this->postJson("/api/calls/{$call->id}/recording/retrieve", [], $this->headers($token))
            ->assertStatus(409);

        Queue::assertNothingPushed();
        $this->assertSame(CallRecord::RECORDING_UNAVAILABLE, $call->refresh()->recording_state);
    }

    public function test_a_fresh_pending_retrieval_is_not_dispatched_twice(): void
    {
        Queue::fake();
        [, $token] = $this->admin();
        $call = $this->makeCall([
            'recording_state' => CallRecord::RECORDING_PENDING,
            'recording_disk' => null,
            'recording_path' => null,
            'recording_provider_reference' => 'rec.wav',
        ]);

        $this->postJson("/api/calls/{$call->id}/recording/retrieve", [], $this->headers($token))
            ->assertStatus(409)
            ->assertJsonPath('data.state', CallRecord::RECORDING_PENDING);

        Queue::assertNothingPushed();
    }

    public function test_a_stale_pending_retrieval_can_be_retried_and_keeps_the_error_summary(): void
    {
        Queue::fake();
        [, $token] = $this->admin();
        $call = $this->makeCall([
            'recording_state' => CallRecord::RECORDING_PENDING,
            'recording_disk' => null,
            'recording_path' => null,
            'recording_provider_reference' => 'rec.wav',
            'recording_error' => 'Tentative précédente interrompue.',
        ]);
        $call->forceFill(['updated_at' => now()->subHour()])->save();

        $this->postJson("/api/calls/{$call->id}/recording/retrieve", [], $this->headers($token))
            ->assertStatus(202)
            ->assertJsonPath('data.state', CallRecord::RECORDING_PENDING);

        Queue::assertPushed(RetrieveCallRecordingJob::class, 1);
        $this->assertNull($call->refresh()->recording_error);
    }

    public function test_call_payload_exposes_the_recording_error_summary(): void
    {
        [, $token] = $this->admin();
        $record = ProductionRecord::create([
            'source_key' => hash('sha256', 'audio-error-record'),
            'client_name' => 'Client Audio',
            'phone' => '0612345678',
            'phone_normalise' => '0612345678',
        ]);
        $call = $this->makeCall([
            'production_record_id' => $record->id,
            'recording_state' => CallRecord::RECORDING_MISSING,
            'recording_disk' => null,
            'recording_path' => null,
            'recording_error' => 'Fichier audio absent du répertoire configuré.',
        ]);

        $this->getJson(
            "/api/production/calls/matches?record_ids[]={$record->id}",
            $this->headers($token),
        )->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.provider_call_id', $call->provider_call_id)
            ->assertJsonPath('data.0.recording.error', 'Fichier audio absent du répertoire configuré.');
    }

    public function test_retrieval_is_refused_for_a_provider_without_documented_download(): void
    {
        [, $token] = $this->admin();
        $call = $this->makeCall([
            'provider' => 'kavkom',
            'recording_state' => CallRecord::RECORDING_REFERENCE_ONLY,
            'recording_disk' => null,
            'recording_path' => null,
        ]);

        $this->postJson("/api/calls/{$call->id}/recording/retrieve", [], $this->headers($token))
            ->assertStatus(409);
    }
}
