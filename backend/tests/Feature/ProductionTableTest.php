<?php

namespace Tests\Feature;

use App\Models\ProductionRecord;
use App\Models\User;
use App\Services\Google\GoogleSheetsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithProductionSheets;
use Tests\TestCase;

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
    private function record(array $attributes): ProductionRecord
    {
        static $n = 0;
        $n++;

        return ProductionRecord::create(array_merge([
            'source_key' => hash('sha256', 'fixture-'.$n),
            'contract_reference' => 'CT-'.$n,
            'client_name' => 'Client '.$n,
            'city' => 'Casablanca',
            'product' => 'Auto',
            'status' => 'Actif',
            'source_row' => $n + 1,
            'last_synced_at' => now(),
        ], $attributes));
    }

    public function test_index_returns_records_with_meta_last_sync_and_filter_options(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([['CT-1', 'Un', 'Rabat', 'Auto', 'Actif']]);
        $this->postJson('/api/production/sync', [], $this->headers($token))->assertCreated();

        $response = $this->getJson('/api/production', $this->headers($token));

        $response->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.contract_reference', 'CT-1')
            ->assertJsonPath('data.0.phone', null)
            ->assertJsonPath('last_sync.status', 'completed')
            ->assertJsonPath('last_sync.performed_by', 'Admin Production')
            ->assertJsonPath('filters.cities.0', 'Rabat')
            ->assertJsonPath('filters.products.0', 'Auto')
            ->assertJsonPath('filters.statuses.0', 'Actif');
    }

    public function test_search_matches_contract_client_and_phone(): void
    {
        [, $token] = $this->actingAdmin();

        $this->record(['contract_reference' => 'CT-ALPHA', 'client_name' => 'Amrani Mohamed', 'phone' => '0611223344']);
        $this->record(['contract_reference' => 'CT-BETA', 'client_name' => 'Benali Ahmed', 'phone' => null]);

        $byContract = $this->getJson('/api/production?search=ALPHA', $this->headers($token));
        $byContract->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame('CT-ALPHA', $byContract->json('data.0.contract_reference'));

        $byClient = $this->getJson('/api/production?search=benali', $this->headers($token));
        $byClient->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame('CT-BETA', $byClient->json('data.0.contract_reference'));

        // Recherche téléphonique : la casse/formatage n'a pas d'importance.
        $byPhone = $this->getJson('/api/production?search=0611223344', $this->headers($token));
        $byPhone->assertOk()->assertJsonPath('meta.total', 1);
        $this->assertSame('CT-ALPHA', $byPhone->json('data.0.contract_reference'));
    }

    public function test_filters_by_city_product_status_and_phone_presence(): void
    {
        [, $token] = $this->actingAdmin();

        $this->record(['city' => 'Rabat', 'product' => 'Auto', 'status' => 'Actif', 'phone' => '0611111111']);
        $this->record(['city' => 'Fès', 'product' => 'Santé', 'status' => 'Résilié', 'phone' => null]);
        $this->record(['city' => 'Rabat', 'product' => 'Santé', 'status' => 'Actif', 'phone' => null]);

        $this->getJson('/api/production?city='.rawurlencode('Rabat'), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/production?product='.rawurlencode('Santé'), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2);

        $this->getJson('/api/production?status='.rawurlencode('Résilié'), $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/production?has_phone=yes', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 1);

        $this->getJson('/api/production?has_phone=no', $this->headers($token))
            ->assertOk()->assertJsonPath('meta.total', 2);
    }

    public function test_sorting_and_pagination(): void
    {
        [, $token] = $this->actingAdmin();

        foreach (range(1, 25) as $i) {
            $this->record(['contract_reference' => 'CT-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT), 'client_name' => 'Client '.$i]);
        }

        $page1 = $this->getJson('/api/production?per_page=10&page=1', $this->headers($token));
        $page1->assertOk()->assertJsonPath('meta.total', 25)->assertJsonPath('meta.last_page', 3);
        $this->assertCount(10, $page1->json('data'));

        $page3 = $this->getJson('/api/production?per_page=10&page=3', $this->headers($token));
        $this->assertCount(5, $page3->json('data'));

        $sorted = $this->getJson('/api/production?sort_by=client_name&sort_direction=desc&per_page=5', $this->headers($token));
        $sorted->assertOk();
        $this->assertSame('Client 9', $sorted->json('data.0.client_name')); // tri lexicographique descendant

        // Tri inconnu : refusé côté serveur, jamais appliqué tel quel.
        $this->getJson('/api/production?sort_by=client_name;drop table users', $this->headers($token))
            ->assertStatus(422);
    }

    public function test_phone_can_be_added_updated_and_cleared(): void
    {
        [, $token] = $this->actingAdmin();
        $record = $this->record([]);

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
        $record = $this->record([]);

        $accepted = ['0612345678', '06 12 34 56 78', '+212 6 12 34 56 78', '+212-612-345-678', '(06) 12.34.56.78', '06/12/34/56/78'];
        foreach ($accepted as $index => $phone) {
            $this->patchJson("/api/production/{$record->id}/phone", ['phone' => $phone], $this->headers($token))
                ->assertOk("Format refusé à tort : {$phone}");
            // La valeur saisie est conservée telle quelle (aucune altération).
            $this->assertSame($phone, $record->refresh()->phone, "Valeur altérée pour {$phone} (#{$index})");
        }

        foreach (['12345', 'abcdefgh', '06 12 34 56 78 poste 12', '+ 0612345678', '12345678901234567890123'] as $phone) {
            $this->patchJson("/api/production/{$record->id}/phone", ['phone' => $phone], $this->headers($token))
                ->assertStatus(422, "Format accepté à tort : {$phone}")
                ->assertJsonValidationErrors('phone');
        }

        // Le champ est obligatoire dans la requête (présence), pour ne pas
        // effacer un numéro par omission.
        $this->patchJson("/api/production/{$record->id}/phone", [], $this->headers($token))
            ->assertStatus(422)
            ->assertJsonValidationErrors('phone');

        // Le numéro précédent (valide) n'a pas été effacé par les refus.
        $this->assertSame('06/12/34/56/78', $record->refresh()->phone);
    }

    public function test_sync_cannot_be_triggered_with_a_client_supplied_spreadsheet(): void
    {
        [, $token] = $this->actingAdmin();
        $reader = $this->fakeSheet([['CT-1', 'Un', null, null, null]]);

        // Le client tente d'imposer un classeur/plage : ignorés par le serveur.
        $this->postJson('/api/production/sync', [
            'spreadsheet_id' => 'un-autre-classeur',
            'range' => 'Secret!A1:Z9',
        ], $this->headers($token))->assertCreated();

        $this->assertSame(1, $reader->calls);
        $this->assertSame(1, ProductionRecord::count());
    }

    public function test_production_endpoints_require_an_active_admin(): void
    {
        $record = $this->record([]);

        // Non authentifié.
        $this->getJson('/api/production')->assertUnauthorized();
        $this->postJson('/api/production/sync')->assertUnauthorized();
        $this->patchJson("/api/production/{$record->id}/phone", ['phone' => '0612345678'])->assertUnauthorized();
        $this->getJson('/api/production/syncs')->assertUnauthorized();

        // Authentifié mais rôle non administrateur.
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

    public function test_sync_history_is_listed_most_recent_first(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([['CT-1', 'Un', null, null, null]]);
        $this->postJson('/api/production/sync', [], $this->headers($token))->assertCreated();

        $this->failReaderWith(GoogleSheetsException::unreachable());
        $this->postJson('/api/production/sync', [], $this->headers($token))->assertStatus(422);

        $response = $this->getJson('/api/production/syncs', $this->headers($token));

        $response->assertOk();
        $this->assertSame('failed', $response->json('data.0.status'));
        $this->assertSame('completed', $response->json('data.1.status'));
    }
}
