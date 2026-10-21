<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CallRecord;
use App\Services\Telephony\Issabel\IssabelRecordingFetcher;
use App\Services\Telephony\Recordings\RecordingStorage;
use App\Services\Telephony\TelephonyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RecordingStorageTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->tempDir = sys_get_temp_dir().'/telephony-test-'.bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0777, true);

        config([
            'telephony.recordings.disk' => 'local',
            'telephony.recordings.path' => 'recordings',
            'telephony.recordings.max_bytes' => 1024 * 1024,
            'telephony.recordings.mime_types' => ['audio/wav', 'audio/x-wav', 'audio/mpeg', 'application/octet-stream'],
            'telephony.issabel.recording_root' => $this->tempDir,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    private function wavFile(string $name = 'source.wav', int $bytes = 256): string
    {
        $path = $this->tempDir.'/'.$name;
        $header = 'RIFF'.pack('V', $bytes).'WAVEfmt ';
        file_put_contents($path, $header.str_repeat("\0", max(0, $bytes - strlen($header))));

        return $path;
    }

    public function test_audio_is_stored_privately_under_an_opaque_name(): void
    {
        $result = app(RecordingStorage::class)->storeFromPath($this->wavFile(), 'issabel', 'wav');

        $this->assertSame('local', $result['disk']);
        $this->assertStringStartsWith('recordings/issabel/', $result['path']);
        $this->assertStringNotContainsString('source.wav', $result['path']);
        $this->assertTrue(Storage::disk('local')->exists($result['path']));
        $this->assertSame($result['size'], Storage::disk('local')->size($result['path']));
    }

    public function test_non_audio_file_is_rejected_and_not_stored(): void
    {
        $path = $this->tempDir.'/malicious.txt';
        file_put_contents($path, '<script>alert(1)</script>');

        try {
            app(RecordingStorage::class)->storeFromPath($path, 'issabel', 'wav');
            $this->fail('Un fichier non audio aurait dû être refusé.');
        } catch (TelephonyException $e) {
            $this->assertSame(TelephonyException::KIND_RECORDING, $e->kind());
        }

        $this->assertSame([], Storage::disk('local')->allFiles('recordings'));
    }

    public function test_oversized_file_is_rejected(): void
    {
        config(['telephony.recordings.max_bytes' => 32]);

        try {
            app(RecordingStorage::class)->storeFromPath($this->wavFile('big.wav', 512), 'issabel', 'wav');
            $this->fail('Un fichier trop volumineux aurait dû être refusé.');
        } catch (TelephonyException $e) {
            $this->assertStringContainsString('taille maximale', $e->getMessage());
        }

        $this->assertSame([], Storage::disk('local')->allFiles('recordings'));
    }

    public function test_issabel_recording_is_retrieved_only_from_the_configured_root(): void
    {
        $this->wavFile('rec-1.wav');

        $call = $this->makeCall(['recording_provider_reference' => 'rec-1.wav']);
        $updated = app(IssabelRecordingFetcher::class)->retrieve($call);

        $this->assertSame(CallRecord::RECORDING_STORED, $updated->recording_state);
        $this->assertNotNull($updated->recording_path);
        $this->assertTrue(Storage::disk('local')->exists($updated->recording_path));
    }

    public function test_nested_relative_recording_path_is_supported_safely(): void
    {
        mkdir($this->tempDir.'/2026/10', 0777, true);
        file_put_contents($this->tempDir.'/2026/10/rec-nested.wav', 'RIFF'.pack('V', 128).'WAVE'.str_repeat("\0", 128));

        $call = $this->makeCall(['recording_provider_reference' => '2026/10/rec-nested.wav']);
        $updated = app(IssabelRecordingFetcher::class)->retrieve($call);

        $this->assertSame(CallRecord::RECORDING_STORED, $updated->recording_state);
        $this->assertTrue(Storage::disk('local')->exists($updated->recording_path));
    }

    public function test_path_traversal_reference_is_refused(): void
    {
        $call = $this->makeCall(['recording_provider_reference' => '../etc/passwd']);

        try {
            app(IssabelRecordingFetcher::class)->retrieve($call);
            $this->fail('Une référence avec traversal aurait dû être refusée.');
        } catch (TelephonyException $e) {
            $this->assertSame(TelephonyException::KIND_RECORDING, $e->kind());
        }

        $this->assertSame([], Storage::disk('local')->allFiles('recordings'));
    }

    public function test_bare_filename_is_resolved_from_the_call_date_directory(): void
    {
        mkdir($this->tempDir.'/2026/10/19', 0777, true);
        file_put_contents(
            $this->tempDir.'/2026/10/19/rec-dated.wav',
            'RIFF'.pack('V', 128).'WAVE'.str_repeat("\0", 128),
        );

        $call = $this->makeCall([
            'recording_provider_reference' => 'rec-dated.wav',
            'started_at' => '2026-10-19 10:00:00',
        ]);

        $updated = app(IssabelRecordingFetcher::class)->retrieve($call);

        $this->assertSame(CallRecord::RECORDING_STORED, $updated->recording_state);
        $this->assertTrue(Storage::disk('local')->exists($updated->recording_path));
    }

    public function test_recording_outside_the_allowed_root_via_symlink_is_refused(): void
    {
        $outside = sys_get_temp_dir().'/issabel-outside-'.bin2hex(random_bytes(6)).'.wav';
        file_put_contents($outside, 'RIFF'.pack('V', 64).'WAVE'.str_repeat("\0", 64));

        if (! @symlink($outside, $this->tempDir.'/escape.wav')) {
            @unlink($outside);
            $this->markTestSkipped('Les liens symboliques ne sont pas disponibles dans cet environnement.');
        }

        $call = $this->makeCall(['recording_provider_reference' => 'escape.wav']);

        try {
            app(IssabelRecordingFetcher::class)->retrieve($call);
            $this->fail('Un lien sortant du répertoire aurait dû être refusé.');
        } catch (TelephonyException $e) {
            $this->assertSame(TelephonyException::KIND_RECORDING, $e->kind());
        } finally {
            @unlink($outside);
        }

        $this->assertSame(CallRecord::RECORDING_MISSING, $call->refresh()->recording_state);
        $this->assertSame([], Storage::disk('local')->allFiles('recordings'));
    }

    public function test_unconfigured_root_is_reported_as_unsupported(): void
    {
        config(['telephony.issabel.recording_root' => '']);
        $call = $this->makeCall();

        try {
            app(IssabelRecordingFetcher::class)->retrieve($call);
            $this->fail('Une racine non configurée aurait dû être signalée.');
        } catch (TelephonyException $e) {
            $this->assertSame(TelephonyException::KIND_UNSUPPORTED, $e->kind());
        }

        $this->assertSame(CallRecord::RECORDING_UNSUPPORTED, $call->refresh()->recording_state);
    }

    public function test_missing_audio_keeps_call_metadata_and_marks_file_missing(): void
    {
        $call = $this->makeCall(['recording_provider_reference' => 'absent.wav']);

        try {
            app(IssabelRecordingFetcher::class)->retrieve($call);
            $this->fail('Un fichier absent aurait dû être signalé.');
        } catch (TelephonyException $e) {
            $this->assertStringContainsString('absent', $e->getMessage());
        }

        $call->refresh();
        $this->assertSame(CallRecord::RECORDING_MISSING, $call->recording_state);
        $this->assertSame('cdr-test', $call->provider_call_id);
        $this->assertSame('0612345678', $call->raw_phone);
    }

    public function test_failed_retrieval_does_not_replace_an_existing_good_recording(): void
    {
        Storage::disk('local')->put('recordings/issabel/good.wav', 'good-audio');
        $call = $this->makeCall([
            'recording_provider_reference' => 'absent.wav',
            'recording_state' => CallRecord::RECORDING_STORED,
            'recording_disk' => 'local',
            'recording_path' => 'recordings/issabel/good.wav',
            'recording_mime' => 'audio/wav',
            'recording_size' => 10,
        ]);

        app(IssabelRecordingFetcher::class)->retrieve($call);

        $call->refresh();
        $this->assertSame(CallRecord::RECORDING_STORED, $call->recording_state);
        $this->assertSame('recordings/issabel/good.wav', $call->recording_path);
        $this->assertSame('good-audio', Storage::disk('local')->get('recordings/issabel/good.wav'));
    }

    private function makeCall(array $attributes = []): CallRecord
    {
        return CallRecord::create(array_merge([
            'provider' => 'issabel',
            'provider_call_id' => 'cdr-test',
            'raw_phone' => '0612345678',
            'matched_phone_e164' => '+33612345678',
            'recording_state' => CallRecord::RECORDING_REFERENCE_ONLY,
            'recording_provider_reference' => 'rec.wav',
        ], $attributes));
    }
}
