<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RetrieveCallRecordingJob;
use App\Models\CallRecord;
use App\Services\Telephony\Issabel\IssabelRecordingFetcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class RecordingRetrievalJobTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->tempDir = sys_get_temp_dir().'/telephony-job-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);

        config([
            'telephony.recordings.disk' => 'local',
            'telephony.recordings.path' => 'recordings',
            'telephony.recordings.mime_types' => ['audio/wav', 'audio/x-wav'],
            'telephony.issabel.recording_root' => $this->tempDir,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    private function wavFile(string $name = 'rec.wav'): void
    {
        file_put_contents(
            $this->tempDir.'/'.$name,
            'RIFF'.pack('V', 128).'WAVE'.str_repeat("\0", 128),
        );
    }

    private function makeCall(array $attributes = []): CallRecord
    {
        return CallRecord::create(array_merge([
            'provider' => 'issabel',
            'provider_call_id' => 'job-cdr-1',
            'recording_state' => CallRecord::RECORDING_PENDING,
            'recording_provider_reference' => 'rec.wav',
        ], $attributes));
    }

    private function runJob(CallRecord $call): void
    {
        (new RetrieveCallRecordingJob($call->id))->handle(app(IssabelRecordingFetcher::class));
    }

    public function test_replaying_the_job_stores_the_recording_once(): void
    {
        $this->wavFile();
        $call = $this->makeCall();

        $this->runJob($call);
        $this->runJob($call);

        $call->refresh();
        $this->assertSame(CallRecord::RECORDING_STORED, $call->recording_state);
        $this->assertCount(1, Storage::disk('local')->allFiles('recordings'));
    }

    public function test_missing_source_file_leaves_a_missing_state_and_no_stored_file(): void
    {
        $call = $this->makeCall(['recording_provider_reference' => 'absent.wav']);

        $this->runJob($call);
        $this->runJob($call);

        $call->refresh();
        $this->assertSame(CallRecord::RECORDING_MISSING, $call->recording_state);
        $this->assertNotSame(CallRecord::RECORDING_STORED, $call->recording_state);
        $this->assertSame([], Storage::disk('local')->allFiles('recordings'));
    }

    public function test_exhausted_job_is_marked_failed_and_never_completed(): void
    {
        $call = $this->makeCall();

        (new RetrieveCallRecordingJob($call->id))->failed(new RuntimeException('worker timeout'));

        $call->refresh();
        $this->assertSame(CallRecord::RECORDING_FAILED, $call->recording_state);
        $this->assertStringContainsString('échoué', (string) $call->recording_error);
    }

    public function test_failure_callback_never_downgrades_a_stored_recording(): void
    {
        Storage::disk('local')->put('recordings/issabel/good.wav', 'good-audio');
        $call = $this->makeCall([
            'recording_state' => CallRecord::RECORDING_STORED,
            'recording_disk' => 'local',
            'recording_path' => 'recordings/issabel/good.wav',
            'recording_mime' => 'audio/wav',
            'recording_size' => 10,
        ]);

        (new RetrieveCallRecordingJob($call->id))->failed(new RuntimeException('worker timeout'));

        $this->assertSame(CallRecord::RECORDING_STORED, $call->refresh()->recording_state);
        $this->assertSame('good-audio', Storage::disk('local')->get('recordings/issabel/good.wav'));
    }

    public function test_non_issabel_call_is_marked_unsupported_without_storing(): void
    {
        $this->wavFile();
        $call = $this->makeCall(['provider' => 'kavkom']);

        $this->runJob($call);

        $call->refresh();
        $this->assertSame(CallRecord::RECORDING_UNSUPPORTED, $call->recording_state);
        $this->assertSame([], Storage::disk('local')->allFiles('recordings'));
    }
}
