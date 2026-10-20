<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Telephony\TelephonyException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Diagnostic de connexion téléphonie, fournisseur par fournisseur.
 *
 * Issabel est vérifié sur une connexion sqlite en mémoire qui remplace la
 * connexion MySQL dédiée : le diagnostic exécute exactement le même code
 * (connexion réelle, inspection du schéma, agrégat) sans PBX.
 */
class TelephonyDiagnosticsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: User, 1: string} */
    private function actingAdmin(): array
    {
        $admin = User::create([
            'name' => 'Admin Diagnostic',
            'email' => 'diagnostics@test.ma',
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

    /**
     * Remplace la connexion Issabel par une base sqlite en mémoire contenant
     * une table CDR avec les colonnes demandées.
     *
     * @param  list<string>  $columns
     */
    private function useCdrDatabase(array $columns = ['calldate', 'src', 'dst', 'uniqueid', 'recordingfile']): void
    {
        config()->set('database.connections.issabel', [
            'driver' => 'sqlite',
            'host' => '127.0.0.1',
            'database' => ':memory:',
            'username' => 'lecture',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('issabel');

        Schema::connection('issabel')->create('cdr', function (Blueprint $table) use ($columns): void {
            $table->id();
            foreach ($columns as $column) {
                $table->string($column)->nullable();
            }
        });
    }

    private function issue(): TestResponse
    {
        [, $token] = $this->actingAdmin();

        return $this->getJson('/api/telephony/diagnostics/issabel', $this->headers($token));
    }

    /** @return array<string, mixed>|null */
    private function check(TestResponse $response, string $key): ?array
    {
        return collect($response->json('data.checks'))->firstWhere('key', $key);
    }

    public function test_diagnostics_require_an_active_admin(): void
    {
        $this->getJson('/api/telephony/diagnostics/kavkom')->assertUnauthorized();

        $agent = User::create([
            'name' => 'Agent',
            'email' => 'agent-diagnostics@test.ma',
            'password' => 'motdepasse1',
            'role' => 'agent',
            'active' => true,
        ]);

        $this->getJson(
            '/api/telephony/diagnostics/kavkom',
            $this->headers($agent->createToken('session')->plainTextToken),
        )->assertForbidden();
    }

    public function test_unknown_provider_is_rejected(): void
    {
        [, $token] = $this->actingAdmin();

        $this->getJson('/api/telephony/diagnostics/inconnu', $this->headers($token))
            ->assertNotFound()
            ->assertJsonPath('message', 'Fournisseur téléphonie inconnu. Fournisseurs disponibles : kavkom, issabel.');
    }

    public function test_kavkom_is_tested_from_configuration_only_and_never_leaks_the_secret(): void
    {
        config()->set('telephony.kavkom.webhook_enabled', true);
        config()->set('telephony.kavkom.webhook_secret', 'secret-kavkom-tres-sensible');
        config()->set('telephony.kavkom.webhook_header', 'X-Kavkom-Webhook-Token');

        [, $token] = $this->actingAdmin();

        $response = $this->getJson('/api/telephony/diagnostics/kavkom', $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.provider', 'kavkom')
            ->assertJsonPath('data.status', 'ok');

        $this->assertSame('ok', $this->check($response, 'webhook_enabled')['status']);
        $this->assertSame('ok', $this->check($response, 'webhook_secret')['status']);
        $this->assertSame('ok', $this->check($response, 'webhook_header')['status']);
        $this->assertStringContainsString('unsupported', $this->check($response, 'capabilities')['message']);

        $this->assertStringNotContainsString('secret-kavkom-tres-sensible', $response->getContent());
        $this->assertArrayNotHasKey('value', $this->check($response, 'webhook_secret'));
    }

    public function test_kavkom_disabled_and_without_secret_is_reported_as_error(): void
    {
        config()->set('telephony.kavkom.webhook_enabled', false);
        config()->set('telephony.kavkom.webhook_secret', '');

        [, $token] = $this->actingAdmin();

        $response = $this->getJson('/api/telephony/diagnostics/kavkom', $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.status', 'error');

        $this->assertSame('warning', $this->check($response, 'webhook_enabled')['status']);
        $this->assertSame('error', $this->check($response, 'webhook_secret')['status']);
        $this->assertStringContainsString('503', $this->check($response, 'webhook_secret')['message']);
    }

    public function test_issabel_disabled_reports_a_warning_and_attempts_no_connection(): void
    {
        config()->set('telephony.issabel.enabled', false);

        $response = $this->issue()->assertOk()->assertJsonPath('data.status', 'warning');

        $this->assertSame('warning', $this->check($response, 'enabled')['status']);
        $this->assertNull($this->check($response, 'connection'));
    }

    public function test_issabel_incomplete_configuration_is_an_error(): void
    {
        config()->set('telephony.issabel.enabled', true);
        config()->set('database.connections.issabel.host', '');
        config()->set('database.connections.issabel.username', '');

        $response = $this->issue()->assertOk()->assertJsonPath('data.status', 'error');

        $check = $this->check($response, 'connection_config');
        $this->assertSame('error', $check['status']);
        $this->assertSame(TelephonyException::KIND_CONFIGURATION, $check['code']);
        $this->assertStringContainsString('hôte', $check['message']);
    }

    public function test_issabel_connection_schema_and_latest_cdr_are_verified(): void
    {
        config()->set('telephony.issabel.enabled', true);
        config()->set('database.connections.issabel.host', '127.0.0.1');
        config()->set('database.connections.issabel.database', 'asteriskcdrdb');
        config()->set('database.connections.issabel.username', 'lecture');
        config()->set('telephony.issabel.agent_column', '');
        config()->set('telephony.issabel.direction_column', '');

        $recordingRoot = sys_get_temp_dir().'/issabel-recordings-'.uniqid();
        mkdir($recordingRoot);
        config()->set('telephony.issabel.recording_root', $recordingRoot);

        $this->useCdrDatabase();
        DB::connection('issabel')->table('cdr')->insert([
            'calldate' => '2026-10-19 10:00:00',
            'src' => '0612345678',
            'dst' => '0140000000',
        ]);

        try {
            $response = $this->issue()->assertOk()->assertJsonPath('data.status', 'ok');
        } finally {
            @rmdir($recordingRoot);
        }

        $this->assertSame('ok', $this->check($response, 'connection')['status']);
        $this->assertSame('ok', $this->check($response, 'table')['status']);
        $this->assertSame('ok', $this->check($response, 'columns')['status']);
        $this->assertSame('ok', $this->check($response, 'latest_cdr')['status']);
        $this->assertStringContainsString('2026-10-19 10:00:00', $this->check($response, 'latest_cdr')['message']);
        $this->assertSame('ok', $this->check($response, 'recording_root')['status']);
        $this->assertIsInt($response->json('data.duration_ms'));
    }

    public function test_issabel_table_without_required_column_is_an_error(): void
    {
        config()->set('telephony.issabel.enabled', true);
        config()->set('database.connections.issabel.host', '127.0.0.1');
        config()->set('database.connections.issabel.database', 'asteriskcdrdb');
        config()->set('database.connections.issabel.username', 'lecture');

        $this->useCdrDatabase(['calldate', 'src']);

        $response = $this->issue()->assertOk()->assertJsonPath('data.status', 'error');

        $check = $this->check($response, 'columns');
        $this->assertSame('error', $check['status']);
        $this->assertSame(TelephonyException::KIND_CONFIGURATION, $check['code']);
        $this->assertStringContainsString('dst', $check['message']);
        $this->assertNull($this->check($response, 'latest_cdr'));
    }

    public function test_issabel_configured_agent_column_absent_is_a_warning(): void
    {
        config()->set('telephony.issabel.enabled', true);
        config()->set('database.connections.issabel.host', '127.0.0.1');
        config()->set('database.connections.issabel.database', 'asteriskcdrdb');
        config()->set('database.connections.issabel.username', 'lecture');
        config()->set('telephony.issabel.agent_column', 'agent');

        $this->useCdrDatabase(['calldate', 'src', 'dst']);

        $response = $this->issue()->assertOk()->assertJsonPath('data.status', 'warning');

        $check = $this->check($response, 'optional_columns');
        $this->assertSame('warning', $check['status']);
        $this->assertStringContainsString('agent', $check['message']);
    }

    public function test_issabel_unreachable_database_is_typed_without_leaking_details(): void
    {
        config()->set('telephony.issabel.enabled', true);
        config()->set('database.connections.issabel', [
            'driver' => 'sqlite',
            'host' => '127.0.0.1',
            'database' => '/aucun-repertoire-diagnostic/cdr.sqlite',
            'username' => 'lecture',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('issabel');

        $response = $this->issue()->assertOk()->assertJsonPath('data.status', 'error');

        $check = $this->check($response, 'connection');
        $this->assertSame('error', $check['status']);
        $this->assertSame(TelephonyException::KIND_PROVIDER_ERROR, $check['code']);
        $this->assertStringNotContainsString('aucun-repertoire-diagnostic', $check['message']);
        $this->assertNull($this->check($response, 'table'));
    }

    public function test_issabel_missing_recording_root_is_reported(): void
    {
        config()->set('telephony.issabel.enabled', true);
        config()->set('database.connections.issabel.host', '127.0.0.1');
        config()->set('database.connections.issabel.database', 'asteriskcdrdb');
        config()->set('database.connections.issabel.username', 'lecture');
        config()->set('telephony.issabel.recording_root', '/aucun-repertoire-enregistrements');

        $this->useCdrDatabase();

        $response = $this->issue()->assertOk()->assertJsonPath('data.status', 'error');

        $check = $this->check($response, 'recording_root');
        $this->assertSame('error', $check['status']);
        $this->assertSame(TelephonyException::KIND_CONFIGURATION, $check['code']);
    }
}
