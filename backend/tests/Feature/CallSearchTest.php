<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Jobs\RunCallSearchJob;
use App\Models\CallRecord;
use App\Models\CallSearchItem;
use App\Models\ProductionRecord;
use App\Models\User;
use App\Services\Telephony\CallPersister;
use App\Services\Telephony\CallProvider;
use App\Services\Telephony\CallProviderRegistry;
use App\Services\Telephony\CallSearchProgress;
use App\Services\Telephony\Kavkom\KavkomCallProvider;
use App\Services\Telephony\ProviderCall;
use App\Services\Telephony\RecordingRetrieval;
use App\Services\Telephony\TelephonyException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\FakeCallProvider;
use Tests\TestCase;

class CallSearchTest extends TestCase
{
    use RefreshDatabase;

    private function actingAdmin(): array
    {
        $admin = User::create([
            'name' => 'Admin Appels',
            'email' => 'calls@test.ma',
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

    private function record(array $attributes = []): ProductionRecord
    {
        static $n = 0;
        $n++;

        return ProductionRecord::create(array_merge([
            'source_key' => hash('sha256', 'call-fixture-'.$n),
            'contract_reference' => 'CT-CALL-'.$n,
            'client_name' => 'Client Appel '.$n,
            'phone' => '06 12 34 56 78',
            'phone_normalise' => '0612345678',
            'city' => 'Paris',
            'product' => 'Auto',
            'status' => 'Actif',
            'source_row' => $n + 1,
            'last_synced_at' => now(),
        ], $attributes));
    }

    private function bindProviders(CallProvider ...$providers): void
    {
        $this->app->instance(CallProviderRegistry::class, new CallProviderRegistry($providers));
    }

    private function providerCall(string $id, string $phone = '0612345678'): ProviderCall
    {
        return new ProviderCall(
            providerCallId: $id,
            rawPhone: $phone,
            rawDestination: '01 40 00 00 00',
            matchedPhoneE164: '+33612345678',
            direction: 'inbound',
            status: 'answered',
            startedAt: now()->subMinutes(5),
            durationSeconds: 120,
            billableSeconds: 118,
            agent: '901',
            recordingState: CallRecord::RECORDING_REFERENCE_ONLY,
            recordingProviderReference: 'rec-'.$id.'.wav',
        );
    }

    public function test_search_requires_an_active_admin(): void
    {
        $record = $this->record();

        $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
        ])->assertUnauthorized();

        $agent = User::create([
            'name' => 'Agent',
            'email' => 'agent-calls@test.ma',
            'password' => 'motdepasse1',
            'role' => 'agent',
            'active' => true,
        ]);

        $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
        ], $this->headers($agent->createToken('session')->plainTextToken))->assertForbidden();
    }

    public function test_successful_search_stores_call_metadata_and_marks_item_completed(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $fake = new FakeCallProvider('issabel');
        $fake->calls = [$this->providerCall('unique-1')];
        $this->bindProviders($fake);

        $response = $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
            'from' => now()->subDays(7)->toDateString(),
            'to' => now()->toDateString(),
        ], $this->headers($token));

        $response->assertCreated()
            ->assertJsonPath('data.search.status', 'completed')
            ->assertJsonPath('data.search.items.0.status', 'completed')
            ->assertJsonPath('data.search.items.0.result_count', 1);

        $call = CallRecord::sole();
        $this->assertSame('issabel', $call->provider);
        $this->assertSame('unique-1', $call->provider_call_id);
        $this->assertSame($record->id, $call->production_record_id);
        $this->assertSame('+33612345678', $call->matched_phone_e164);
        $this->assertSame(120, $call->duration_seconds);
        $this->assertSame('901', $call->agent);
        $this->assertSame(CallRecord::RECORDING_REFERENCE_ONLY, $call->recording_state);

        $this->getJson("/api/production/{$record->id}/calls", $this->headers($token))
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.provider', 'issabel');
    }

    public function test_successful_empty_search_is_a_true_no_results_state(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $fake = new FakeCallProvider('issabel');
        $this->bindProviders($fake);

        $response = $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
        ], $this->headers($token));

        $response->assertCreated()
            ->assertJsonPath('data.search.status', 'completed')
            ->assertJsonPath('data.search.items.0.status', 'no_results')
            ->assertJsonPath('data.search.items.0.result_count', 0);

        $this->assertSame(0, CallRecord::count());
    }

    public function test_provider_failure_is_not_converted_to_no_results(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $fake = new FakeCallProvider('issabel');
        $fake->error = TelephonyException::permission('Issabel');
        $this->bindProviders($fake);

        $response = $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
        ], $this->headers($token));

        $response->assertCreated()
            ->assertJsonPath('data.search.status', 'failed')
            ->assertJsonPath('data.search.items.0.status', 'failed')
            ->assertJsonPath('data.search.items.0.error_code', TelephonyException::KIND_PERMISSION);

        $this->assertSame(0, CallRecord::count());
    }

    public function test_timeout_is_recorded_as_a_distinct_failure_after_retries_exhausted(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        Queue::fake();
        $fake = new FakeCallProvider('issabel');
        $fake->error = TelephonyException::timeout('Issabel');
        $this->bindProviders($fake);

        $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
        ], $this->headers($token))->assertCreated();

        $item = CallSearchItem::sole();
        $job = new RunCallSearchJob($item->id);
        $job->tries = 1; // simule le dernier essai
        $job->handle(
            $this->app->make(CallProviderRegistry::class),
            $this->app->make(CallPersister::class),
            $this->app->make(CallSearchProgress::class),
            $this->app->make(RecordingRetrieval::class),
        );

        $this->assertSame(CallSearchItem::STATUS_FAILED, $item->refresh()->status);
        $this->assertSame(TelephonyException::KIND_TIMEOUT, $item->error_code);
        $this->assertSame(0, CallRecord::count());
    }

    public function test_kavkom_search_is_explicitly_unsupported_and_never_a_no_result(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $this->bindProviders(new KavkomCallProvider);

        $response = $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['kavkom'],
        ], $this->headers($token));

        $response->assertCreated()
            ->assertJsonPath('data.search.status', 'partial')
            ->assertJsonPath('data.search.items.0.status', 'unsupported')
            ->assertJsonPath('data.search.items.0.error_code', TelephonyException::KIND_UNSUPPORTED);

        $this->assertSame(0, CallRecord::count());
    }

    public function test_foreign_or_missing_phone_is_not_searched(): void
    {
        [, $token] = $this->actingAdmin();
        $foreign = $this->record(['phone' => '+212 6 12 34 56 78', 'phone_normalise' => '212612345678']);
        $missing = $this->record(['phone' => null, 'phone_normalise' => null]);

        $fake = new FakeCallProvider('issabel');
        $this->bindProviders($fake);

        $response = $this->postJson('/api/production/calls/search', [
            'record_ids' => [$foreign->id, $missing->id],
            'providers' => ['issabel'],
        ], $this->headers($token));

        $response->assertCreated()->assertJsonPath('data.search.status', 'partial');
        $statuses = collect($response->json('data.search.items'))->pluck('status')->all();
        $this->assertContains('invalid_phone', $statuses);
        $this->assertContains('skipped_no_phone', $statuses);
        $this->assertSame(0, $fake->callCount);
    }

    public function test_shared_phone_is_ambiguous_and_never_associated_automatically(): void
    {
        [, $token] = $this->actingAdmin();
        $first = $this->record(['contract_reference' => 'CT-DUP-1']);
        $second = $this->record(['contract_reference' => 'CT-DUP-2']);

        $fake = new FakeCallProvider('issabel');
        $fake->calls = [$this->providerCall('unique-dup')];
        $this->bindProviders($fake);

        $response = $this->postJson('/api/production/calls/search', [
            'record_ids' => [$first->id, $second->id],
            'providers' => ['issabel'],
        ], $this->headers($token));

        $response->assertCreated();
        $statuses = collect($response->json('data.search.items'))->pluck('status')->all();
        $this->assertSame(['ambiguous', 'ambiguous'], $statuses);
        $this->assertSame(0, $fake->callCount);
        $this->assertSame(0, CallRecord::count());
    }

    public function test_requested_recordings_are_retrieved_automatically(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        Storage::fake('local');
        $root = sys_get_temp_dir().'/issabel-auto-'.bin2hex(random_bytes(6));
        mkdir($root.'/2026', 0777, true);
        mkdir($root.'/2026/10', 0777, true);
        file_put_contents(
            $root.'/2026/10/rec-auto.wav',
            'RIFF'.pack('V', 128).'WAVE'.str_repeat("\0", 128),
        );

        config([
            'telephony.issabel.recording_root' => $root,
            'telephony.recordings.disk' => 'local',
            'telephony.recordings.mime_types' => ['audio/wav', 'audio/x-wav'],
        ]);

        $call = $this->providerCall('unique-auto');
        $call = new ProviderCall(
            providerCallId: $call->providerCallId,
            rawPhone: $call->rawPhone,
            rawDestination: $call->rawDestination,
            matchedPhoneE164: $call->matchedPhoneE164,
            status: $call->status,
            startedAt: $call->startedAt,
            durationSeconds: $call->durationSeconds,
            recordingState: CallRecord::RECORDING_REFERENCE_ONLY,
            recordingProviderReference: '2026/10/rec-auto.wav',
        );

        $fake = new FakeCallProvider('issabel');
        $fake->calls = [$call];
        $this->bindProviders($fake);

        try {
            $this->postJson('/api/production/calls/search', [
                'record_ids' => [$record->id],
                'providers' => ['issabel'],
                'retrieve_recordings' => true,
            ], $this->headers($token))->assertCreated();

            $stored = CallRecord::sole();
            $this->assertSame(CallRecord::RECORDING_STORED, $stored->recording_state);
            $this->assertNotNull($stored->recording_path);
            $this->assertTrue(Storage::disk('local')->exists($stored->recording_path));
        } finally {
            File::deleteDirectory($root);
        }
    }

    public function test_identical_search_in_progress_is_not_queued_twice(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        Queue::fake();
        $fake = new FakeCallProvider('issabel');
        $this->bindProviders($fake);

        $payload = [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
            'from' => now()->subDays(3)->toDateString(),
            'to' => now()->toDateString(),
        ];

        $first = $this->postJson('/api/production/calls/search', $payload, $this->headers($token))
            ->assertCreated()
            ->json('data.search.id');

        $second = $this->postJson('/api/production/calls/search', $payload, $this->headers($token))
            ->assertCreated()
            ->json('data.search.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, CallSearchItem::count());
        Queue::assertPushed(RunCallSearchJob::class, 1);
    }

    public function test_existing_matches_are_listed_for_selected_records(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $fake = new FakeCallProvider('issabel');
        $fake->calls = [$this->providerCall('unique-matches')];
        $this->bindProviders($fake);

        $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
        ], $this->headers($token))->assertCreated();

        $response = $this->getJson(
            '/api/production/calls/matches?record_ids[]='.$record->id,
            $this->headers($token),
        )->assertOk()->assertJsonPath('meta.total', 1);

        $this->assertSame('unique-matches', $response->json('data.0.provider_call_id'));
        $this->assertSame(CallRecord::RECORDING_REFERENCE_ONLY, $response->json('data.0.recording.state'));
        $this->assertArrayHasKey('error', $response->json('data.0.recording'));
    }

    public function test_repeated_search_updates_without_duplicating_calls(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $fake = new FakeCallProvider('issabel');
        $fake->calls = [$this->providerCall('unique-repeat')];
        $this->bindProviders($fake);

        $payload = ['record_ids' => [$record->id], 'providers' => ['issabel']];

        $this->postJson('/api/production/calls/search', $payload, $this->headers($token))->assertCreated();
        $firstSeen = CallRecord::sole()->last_seen_at;

        $this->travel(2)->minutes();
        $fake->calls = [$this->providerCall('unique-repeat')];
        $this->postJson('/api/production/calls/search', $payload, $this->headers($token))->assertCreated();

        $this->assertSame(1, CallRecord::count());
        $this->assertTrue(CallRecord::sole()->last_seen_at->greaterThan($firstSeen));
    }

    public function test_same_phone_with_distinct_provider_ids_remains_distinct(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $fake = new FakeCallProvider('issabel');
        $fake->calls = [$this->providerCall('unique-a'), $this->providerCall('unique-b')];
        $this->bindProviders($fake);

        $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
        ], $this->headers($token))->assertCreated();

        $this->assertSame(2, CallRecord::count());
        $this->assertEqualsCanonicalizing(['unique-a', 'unique-b'], CallRecord::pluck('provider_call_id')->all());
    }

    public function test_search_rejects_excessive_date_range_and_too_many_records(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
            'from' => '2019-01-01',
            'to' => now()->toDateString(),
        ], $this->headers($token))->assertStatus(422)->assertJsonValidationErrors('to');

        $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
            'to' => now()->subYears(2)->toDateString(),
        ], $this->headers($token))->assertStatus(422)->assertJsonValidationErrors('to');

        $this->postJson('/api/production/calls/search', [
            'record_ids' => [],
            'providers' => ['issabel'],
        ], $this->headers($token))->assertStatus(422)->assertJsonValidationErrors('record_ids');
    }

    public function test_unknown_provider_is_rejected_by_validation(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['inconnu'],
        ], $this->headers($token))->assertStatus(422)->assertJsonValidationErrors('providers.0');
    }

    public function test_search_item_states_are_visible_without_provider_payload(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $fake = new FakeCallProvider('issabel');
        $fake->error = TelephonyException::permission('Issabel');
        $this->bindProviders($fake);

        $created = $this->postJson('/api/production/calls/search', [
            'record_ids' => [$record->id],
            'providers' => ['issabel'],
        ], $this->headers($token))->assertCreated();

        $searchId = $created->json('data.search.id');

        $this->getJson("/api/production/calls/searches/{$searchId}", $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.items.0.status', 'failed')
            ->assertJsonPath('data.items.0.record.id', $record->id);

        $item = CallSearchItem::sole();
        $this->assertStringNotContainsString('motdepasse', (string) $item->error_message);
    }
}
