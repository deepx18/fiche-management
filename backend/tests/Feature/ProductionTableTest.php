<?php

namespace Tests\Feature;

use App\Models\ProductionRecord;
use App\Models\User;
use App\Services\Google\GoogleSheetsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithProductionSheets;
use Tests\TestCase;

/**
 * Fixtures synthétiques (aucune donnée client réelle).
 */
class ProductionTableTest extends TestCase
{
    use InteractsWithProductionSheets, RefreshDatabase;

    private function actingAdmin(): array
    {
        $admin = User::create([
            'name' => 'Admin Production',
            'email' => 'prod@test.ma',
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

    /** @param array<string, mixed> $attributes */
    private function record(array $attributes = []): ProductionRecord
    {
        static $n = 0;
        $n++;

        return ProductionRecord::createWithReference(array_merge([
            'source_key' => hash('sha256', 'fixture-'.$n),
            'date' => '2026-10-01',
            'cial' => str_pad((string) $n, 6, '0', STR_PAD_LEFT),
            'client_name' => 'Client '.$n,
            'first_name' => 'Prenom '.$n,
            'cotisation' => '91.55',
            'premium_annual' => '1098.6',
            'cie' => 'KIASSURE',
            'product' => 'COVER UP - N3',
            'date_effet' => '2026-12-01',
            'validation' => 'CHLOE',
            'type_signature' => 'SMS',
            'last_synced_at' => now(),
        ], $attributes));
    }

    public function test_index_returns_the_mapped_fields_meta_and_filter_options(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([$this->sheetRow()]);
        $this->postJson('/api/production/sync', [], $this->headers($token))->assertCreated();

        $response = $this->getJson('/api/production', $this->headers($token));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.cial', '0123456')
            ->assertJsonPath('data.0.nom', 'FOURRIQUES')
            ->assertJsonPath('data.0.prenom', 'Danielle')
            ->assertJsonPath('data.0.date', '2026-10-01')
            ->assertJsonPath('data.0.date_effet', '2026-12-01')
            ->assertJsonPath('data.0.cotisation', '91.55')
            ->assertJsonPath('data.0.prime_annuelle', '1098.6')
            ->assertJsonPath('data.0.cie', 'KIASSURE')
            ->assertJsonPath('data.0.produit', 'COVER UP - N3')
            ->assertJsonPath('data.0.validation', 'CHLOE')
            ->assertJsonPath('data.0.type_signature', 'SMS')
            ->assertJsonPath('data.0.phone', null)
            ->assertJsonPath('last_sync.status', 'completed')
            ->assertJsonPath('last_sync.performed_by', 'Admin Production')
            ->assertJsonPath('filters.cies.0', 'KIASSURE')
            ->assertJsonPath('filters.produits.0', 'COVER UP - N3')
            ->assertJsonPath('filters.validations.0', 'CHLOE')
            ->assertJsonPath('filters.type_signatures.0', 'SMS');

        $this->assertMatchesRegularExpression(
            '/^CTR-\d{4}-[A-Z0-9]{8}$/',
            (string) $response->json('data.0.reference_contrat'),
        );
    }

    public function test_search_matches_reference_names_cial_insurer_and_phone(): void
    {
        [, $token] = $this->actingAdmin();

        $alpha = $this->record([
            'reference_contrat' => 'CTR-2026-ALPHA123',
            'client_name' => 'Amrani',
            'first_name' => 'Mohamed',
            'cial' => '000987',
            'cie' => 'NEOLIANE',
            'product' => 'Energik - EN1',
            'phone' => '0611223344',
        ]);
        $this->record([
            'reference_contrat' => 'CTR-2026-BETA4567',
            'client_name' => 'Benali',
            'first_name' => 'Ahmed',
            'cial' => '000123',
            'cie' => 'KIASSURE',
            'product' => 'INSTASSUR - Niveau 1',
        ]);

        foreach (['ALPHA123', 'Amrani', 'Mohamed', '000987', 'NEOLIANE', '0611223344'] as $term) {
            $response = $this->getJson('/api/production?search='.rawurlencode($term), $this->headers($token));
            $response->assertOk()->assertJsonPath('meta.total', 1);
            $this->assertSame($alpha->id, $response->json('data.0.id'), "Recherche « {$term} »");
        }
    }

    public function test_filters_by_cie_produit_validation_signature_and_phone_presence(): void
    {
        [, $token] = $this->actingAdmin();

        $this->record(['cie' => 'KIASSURE', 'product' => 'A', 'validation' => 'CHLOE', 'type_signature' => 'SMS', 'phone' => '0611111111']);
        $this->record(['cie' => 'NEOLIANE', 'product' => 'B', 'validation' => 'CARINE', 'type_signature' => 'MAIL', 'phone' => null]);
        $this->record(['cie' => 'KIASSURE', 'product' => 'B', 'validation' => 'CHLOE', 'type_signature' => null, 'phone' => null]);

        $this->getJson('/api/production?cie=KIASSURE', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/production?produit=B', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/production?validation='.rawurlencode('CARINE'), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/production?type_signature=MAIL', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/production?has_phone=yes', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/production?has_phone=no', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_sorting_and_pagination_use_whitelisted_columns(): void
    {
        [, $token] = $this->actingAdmin();

        foreach (range(1, 25) as $i) {
            $this->record([
                'cial' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'client_name' => 'Client '.str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            ]);
        }

        $page1 = $this->getJson('/api/production?per_page=10&page=1', $this->headers($token));
        $page1->assertOk()->assertJsonPath('meta.total', 25)->assertJsonPath('meta.last_page', 3);
        $this->assertCount(10, $page1->json('data'));

        $page3 = $this->getJson('/api/production?per_page=10&page=3', $this->headers($token));
        $this->assertCount(5, $page3->json('data'));

        $sorted = $this->getJson('/api/production?sort_by=nom&sort_direction=desc&per_page=5', $this->headers($token));
        $sorted->assertOk();
        $this->assertSame('Client 25', $sorted->json('data.0.nom'));

        $byDate = $this->getJson('/api/production?sort_by=date_effet&sort_direction=asc&per_page=5', $this->headers($token));
        $byDate->assertOk();

        // Tri inconnu : refusé côté serveur, jamais appliqué tel quel.
        $this->getJson('/api/production?sort_by=client_name;drop table users', $this->headers($token))
            ->assertStatus(422);
    }

    public function test_phone_can_be_added_updated_and_cleared(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $this->patchJson("/api/production/{$record->id}/phone", ['phone' => '+212612345678'], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.phone', '+212612345678');

        $this->patchJson("/api/production/{$record->id}/phone", ['phone' => '06 12 34 56 78'], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.phone', '06 12 34 56 78');

        $this->assertSame('0612345678', $record->refresh()->phone_normalise);

        $this->patchJson("/api/production/{$record->id}/phone", ['phone' => ''], $this->headers($token))
            ->assertOk()
            ->assertJsonPath('data.phone', null);

        $this->assertNull($record->refresh()->phone_normalise);
    }

    public function test_phone_validation_accepts_intended_formats_and_rejects_the_rest(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record();

        $accepted = ['0612345678', '06 12 34 56 78', '+212 6 12 34 56 78', '+212-612-345-678', '(06) 12.34.56.78', '06/12/34/56/78'];
        foreach ($accepted as $phone) {
            $this->patchJson("/api/production/{$record->id}/phone", ['phone' => $phone], $this->headers($token))
                ->assertOk("Format refusé à tort : {$phone}");
            $this->assertSame($phone, $record->refresh()->phone, "Valeur altérée pour {$phone}");
        }

        foreach (['12345', 'abcdefgh', '06 12 34 56 78 poste 12', '+ 0612345678', '12345678901234567890123'] as $phone) {
            $this->patchJson("/api/production/{$record->id}/phone", ['phone' => $phone], $this->headers($token))
                ->assertStatus(422, "Format accepté à tort : {$phone}")
                ->assertJsonValidationErrors('phone');
        }

        $this->patchJson("/api/production/{$record->id}/phone", [], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        $this->assertSame('06/12/34/56/78', $record->refresh()->phone);
    }

    public function test_sync_cannot_be_triggered_with_a_client_supplied_spreadsheet(): void
    {
        [, $token] = $this->actingAdmin();
        $reader = $this->fakeSheet([$this->sheetRow()]);

        $this->postJson('/api/production/sync', [
            'spreadsheet_id' => 'un-autre-classeur',
            'range' => 'Secret!A1:Z9',
        ], $this->headers($token))->assertCreated();

        $this->assertSame(1, $reader->calls);
        $this->assertSame(1, ProductionRecord::count());
    }

    public function test_production_endpoints_require_an_active_admin(): void
    {
        $record = $this->record();

        $this->getJson('/api/production')->assertUnauthorized();
        $this->postJson('/api/production/sync')->assertUnauthorized();
        $this->patchJson("/api/production/{$record->id}/phone", ['phone' => '0612345678'])->assertUnauthorized();
        $this->getJson('/api/production/syncs')->assertUnauthorized();

        $agent = User::create([
            'name' => 'Agent',
            'email' => 'agent@test.ma',
            'password' => 'motdepasse1',
            'role' => 'agent',
            'active' => true,
        ]);
        $agentToken = $agent->createToken('session')->plainTextToken;

        $this->getJson('/api/production', $this->headers($agentToken))->assertForbidden();
        $this->postJson('/api/production/sync', [], $this->headers($agentToken))->assertForbidden();
        $this->patchJson("/api/production/{$record->id}/phone", ['phone' => '0612345678'], $this->headers($agentToken))->assertForbidden();
    }

    public function test_sync_history_is_listed_most_recent_first_and_includes_warnings(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([$this->sheetRow()]);
        $this->postJson('/api/production/sync', [], $this->headers($token))->assertCreated();

        $this->failReaderWith(GoogleSheetsException::unreachable());
        $this->postJson('/api/production/sync', [], $this->headers($token))->assertStatus(422);

        $response = $this->getJson('/api/production/syncs', $this->headers($token));

        $response->assertOk();
        $this->assertSame('failed', $response->json('data.0.status'));
        $this->assertSame('completed', $response->json('data.1.status'));
        $this->assertArrayHasKey('warnings', $response->json('data.1'));
    }
}
