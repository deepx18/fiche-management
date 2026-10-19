<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CallRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PruneTelephonyRecordingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_expired_audio_is_deleted_but_call_metadata_is_kept(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('recordings/issabel/old.wav', 'old');
        Storage::disk('local')->put('recordings/issabel/recent.wav', 'recent');

        config([
            'telephony.recordings.retention_days' => 30,
            'telephony.recordings.disk' => 'local',
        ]);

        $old = $this->makeCall('old-cdr', 'recordings/issabel/old.wav', now()->subDays(60));
        $recent = $this->makeCall('recent-cdr', 'recordings/issabel/recent.wav', now()->subDays(2));

        $this->artisan('telephony:prune-recordings')->assertSuccessful();

        $this->assertFalse(Storage::disk('local')->exists('recordings/issabel/old.wav'));
        $this->assertTrue(Storage::disk('local')->exists('recordings/issabel/recent.wav'));

        $this->assertSame(CallRecord::RECORDING_EXPIRED, $old->refresh()->recording_state);
        $this->assertNull($old->recording_path);
        $this->assertSame('old-cdr', $old->provider_call_id); // métadonnée conservée
        $this->assertSame(CallRecord::RECORDING_STORED, $recent->refresh()->recording_state);
    }

    public function test_dry_run_deletes_nothing(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('recordings/issabel/old.wav', 'old');

        config(['telephony.recordings.retention_days' => 30]);
        $call = $this->makeCall('old-cdr', 'recordings/issabel/old.wav', now()->subDays(60));

        $this->artisan('telephony:prune-recordings', ['--dry-run' => true])->assertSuccessful();

        $this->assertTrue(Storage::disk('local')->exists('recordings/issabel/old.wav'));
        $this->assertSame(CallRecord::RECORDING_STORED, $call->refresh()->recording_state);
    }

    private function makeCall(string $id, string $path, \DateTimeInterface $retrievedAt): CallRecord
    {
        return CallRecord::create([
            'provider' => 'issabel',
            'provider_call_id' => $id,
            'recording_state' => CallRecord::RECORDING_STORED,
            'recording_disk' => 'local',
            'recording_path' => $path,
            'recording_mime' => 'audio/wav',
            'recording_size' => 10,
            'recording_retrieved_at' => $retrievedAt,
        ]);
    }
}
