<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\CallRecord;
use App\Models\ProductionRecord;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KavkomWebhookTest extends TestCase
{
    use RefreshDatabase;

    private function configureWebhook(): void
    {
        config([
            'telephony.kavkom.webhook_enabled' => true,
            'telephony.kavkom.webhook_secret' => 'webhook-secret-test',
            'telephony.kavkom.webhook_header' => 'X-Kavkom-Webhook-Token',
        ]);
    }

    private function record(string $phone = '06 12 34 56 78', string $normalized = '0612345678'): ProductionRecord
    {
        static $n = 0;
        $n++;

        return ProductionRecord::create([
            'source_key' => hash('sha256', 'webhook-fixture-'.$n),
            'contract_reference' => 'CT-WEB-'.$n,
            'client_name' => 'Client Webhook '.$n,
            'phone' => $phone,
            'phone_normalise' => $normalized,
            'city' => 'Paris',
            'product' => 'Auto',
            'status' => 'Actif',
        ]);
    }

    private function payload(array $overrides = []): array
    {
        return array_merge([
            'xml_cdr_uuid' => 'cdr-uuid-1',
            'direction' => 'outbound',
            'status' => 'answered',
            'duration' => 60,
            'billsec' => 55,
            'outside_number' => '06 12 34 56 78',
            'destination_number' => '0612345678',
            'caller_id_number' => '0140000000',
            'referenced_extension' => '901',
            'start_epoch' => 1760000000,
            'end_epoch' => 1760000060,
            'recording_url' => 'https://recordings.example.invalid/private-signed-token',
        ], $overrides);
    }

    private function postWebhook(array $payload, string $secret = 'webhook-secret-test')
    {
        return $this->postJson('/api/webhooks/kavkom/cdr', $payload, [
            'Accept' => 'application/json',
            'X-Kavkom-Webhook-Token' => $secret,
        ]);
    }

    public function test_valid_cdr_is_stored_idempotently_and_associated_once(): void
    {
        $this->configureWebhook();
        $record = $this->record();

        $this->postWebhook($this->payload())->assertStatus(202)->assertJsonPath('status', 'accepted');

        $call = CallRecord::sole();
        $this->assertSame('kavkom', $call->provider);
        $this->assertSame('cdr-uuid-1', $call->provider_call_id);
        $this->assertSame($record->id, $call->production_record_id);
        $this->assertSame('+33612345678', $call->matched_phone_e164);
        $this->assertSame(60, $call->duration_seconds);
        $this->assertSame('901', $call->agent);
        $this->assertSame(CallRecord::RECORDING_REFERENCE_ONLY, $call->recording_state);
        $this->assertNull($call->recording_provider_reference);

        $metadata = json_encode($call->provider_metadata);
        $this->assertStringNotContainsString('recordings.example.invalid', (string) $metadata);
        $this->assertStringNotContainsString('private-signed-token', (string) $metadata);
        $this->assertTrue((bool) ($call->provider_metadata['recording_url_present'] ?? false));

        $this->postWebhook($this->payload())->assertStatus(202);
        $this->assertSame(1, CallRecord::count());
    }

    public function test_invalid_secret_is_rejected_without_storing_anything(): void
    {
        $this->configureWebhook();

        $this->postWebhook($this->payload(), 'mauvais-secret')->assertUnauthorized();
        $this->assertSame(0, CallRecord::count());
    }

    public function test_missing_webhook_configuration_is_clear_and_safe(): void
    {
        config([
            'telephony.kavkom.webhook_enabled' => true,
            'telephony.kavkom.webhook_secret' => null,
        ]);

        $this->postWebhook($this->payload())->assertStatus(503);
        $this->assertSame(0, CallRecord::count());
    }

    public function test_disabled_webhook_returns_not_found(): void
    {
        config(['telephony.kavkom.webhook_enabled' => false]);

        $this->postWebhook($this->payload())->assertNotFound();
    }

    public function test_live_event_without_cdr_id_is_ignored(): void
    {
        $this->configureWebhook();

        $this->postWebhook([
            'callId' => 'live-1',
            'direction' => 'inbound',
            'number' => '0612345678',
            'state' => 'ringing',
        ])->assertStatus(202)->assertJsonPath('status', 'ignored');

        $this->assertSame(0, CallRecord::count());
    }

    public function test_empty_test_request_is_accepted_without_creating_data(): void
    {
        $this->configureWebhook();

        $this->postWebhook([])->assertStatus(202)->assertJsonPath('status', 'ignored');
        $this->assertSame(0, CallRecord::count());
    }

    public function test_malformed_json_is_rejected_with_a_clear_status(): void
    {
        $this->configureWebhook();

        $this->call(
            'POST',
            '/api/webhooks/kavkom/cdr',
            [],
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/json',
                'HTTP_ACCEPT' => 'application/json',
                'HTTP_X_KAVKOM_WEBHOOK_TOKEN' => 'webhook-secret-test',
            ],
            '{"xml_cdr_uuid":',
        )->assertStatus(422);

        $this->assertSame(0, CallRecord::count());
    }

    public function test_shared_phone_remains_unassociated_and_marked_ambiguous(): void
    {
        $this->configureWebhook();
        $this->record();
        $this->record();

        $this->postWebhook($this->payload())->assertStatus(202);

        $call = CallRecord::sole();
        $this->assertNull($call->production_record_id);
        $this->assertSame('ambiguous', $call->match_basis);
    }

    public function test_unknown_direction_prevents_automatic_association(): void
    {
        $this->configureWebhook();
        $this->record();

        $this->postWebhook($this->payload(['direction' => null]))->assertStatus(202);

        $call = CallRecord::sole();
        $this->assertNull($call->production_record_id);
        $this->assertSame('ambiguous', $call->match_basis);
    }

    public function test_foreign_phone_is_stored_without_client_association(): void
    {
        $this->configureWebhook();
        $this->record('+212 6 12 34 56 78', '212612345678');

        $this->postWebhook($this->payload([
            'outside_number' => '+212612345678',
            'destination_number' => '+212612345678',
        ]))->assertStatus(202);

        $call = CallRecord::sole();
        $this->assertNull($call->production_record_id);
        $this->assertSame('webhook', $call->match_basis);
    }
}
