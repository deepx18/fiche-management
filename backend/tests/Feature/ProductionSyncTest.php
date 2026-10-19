<?php

namespace Tests\Feature;

use App\Models\ProductionRecord;
use App\Models\ProductionSync;
use App\Models\User;
use App\Services\Google\GoogleSheetsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\InteractsWithProductionSheets;
use Tests\TestCase;

class ProductionSyncTest extends TestCase
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

    private function authHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    public function test_sync_creates_records_from_sheet_rows(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([
            ['CT-1001', 'Amrani Mohamed', 'Casablanca', 'Auto', 'Actif'],
            ['CT-1002', 'Benali Ahmed', 'Rabat', 'Santé', 'Actif'],
        ]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(2, ProductionRecord::count());

        $record = ProductionRecord::where('contract_reference', 'CT-1001')->first();
        $this->assertSame('Amrani Mohamed', $record->client_name);
        $this->assertSame('Casablanca', $record->city);
        $this->assertSame('Auto', $record->product);
        $this->assertSame('Actif', $record->status);
        $this->assertNull($record->phone); // jamais alimenté par la feuille
        $this->assertSame(2, $record->source_row); // en-tête = ligne 1
        $this->assertNotNull($record->last_synced_at);

        $sync = ProductionSync::latest('id')->first();
        $this->assertSame(ProductionSync::STATUS_COMPLETED, $sync->status);
        $this->assertSame(2, $sync->rows_read);
        $this->assertSame(2, $sync->created_count);
        $this->assertSame(0, $sync->updated_count);
        $this->assertSame(0, $sync->failed_count);

        // Le résumé renvoyé est celui de la synchronisation courante.
        $this->assertSame(2, $response->json('data.sync.created_count'));
        $this->assertSame('completed', $response->json('data.sync.status'));
    }

    public function test_re_syncing_the_same_sheet_does_not_create_duplicates(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([['CT-1001', 'Amrani Mohamed', 'Casablanca', 'Auto', 'Actif']]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $this->fakeSheet([['CT-1001', 'Amrani Mohamed', 'Casablanca', 'Auto', 'Actif']]);
        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(1, ProductionRecord::count());
        $this->assertSame(0, $response->json('data.sync.created_count'));
        $this->assertSame(1, $response->json('data.sync.updated_count'));
    }

    public function test_re_sync_updates_spreadsheet_owned_fields(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([['CT-1001', 'Amrani Mohamed', 'Casablanca', 'Auto', 'Actif']]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $this->fakeSheet([['CT-1001', 'Amrani Mohamed', 'Marrakech', 'Auto', 'Résilié']]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $record = ProductionRecord::sole();
        $this->assertSame('Marrakech', $record->city);
        $this->assertSame('Résilié', $record->status);
        $this->assertSame(1, ProductionRecord::count());
    }

    public function test_re_sync_preserves_manually_entered_phone(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([['CT-1001', 'Amrani Mohamed', 'Casablanca', 'Auto', 'Actif']]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $record = ProductionRecord::sole();
        $this->patchJson("/api/production/{$record->id}/phone", ['phone' => '+212 6 12 34 56 78'], $this->authHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.phone', '+212 6 12 34 56 78');

        // Nouvelle synchronisation qui change les champs de la feuille.
        $this->fakeSheet([['CT-1001', 'Amrani Mohamed', 'Marrakech', 'Auto', 'Résilié']]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $record->refresh();
        $this->assertSame('+212 6 12 34 56 78', $record->phone); // saisie préservée
        $this->assertSame('212612345678', $record->phone_normalise); // dérivé, pour la recherche
        $this->assertSame('Marrakech', $record->city); // champ feuille mis à jour
    }

    public function test_rows_missing_required_identity_are_reported_and_not_imported(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([
            ['CT-1001', 'Amrani Mohamed', 'Casablanca', 'Auto', 'Actif'],
            ['CT-1002', null, 'Rabat', 'Santé', 'Actif'], // client manquant
        ]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(1, ProductionRecord::count());
        $this->assertSame('partial', $response->json('data.sync.status'));
        $this->assertSame(1, $response->json('data.sync.failed_count'));
        $this->assertSame(3, $response->json('data.sync.row_errors.0.row'));
        $this->assertSame('CLIENT_NAME', $response->json('data.sync.row_errors.0.field'));
        // Aucune donnée personnelle dans les erreurs : uniquement ligne/champ/motif.
        $this->assertArrayNotHasKey('value', $response->json('data.sync.row_errors.0'));
    }

    public function test_empty_and_incomplete_rows_do_not_crash_the_sync(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([
            ['CT-1001', 'Amrani Mohamed', null, null, null],
            [null, null, null, null, null],      // ligne vide → ignorée
            ['', '   ', null, null, null],       // cellules vides → ignorée
            [null, 'Sans contrat', null, null, null], // obligatoire manquant → rejetée
        ]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(1, $response->json('data.sync.created_count'));
        $this->assertSame(2, $response->json('data.sync.skipped_count'));
        $this->assertSame(1, $response->json('data.sync.failed_count'));
        $this->assertSame(4, $response->json('data.sync.rows_read'));
        $this->assertSame('partial', $response->json('data.sync.status'));
    }

    public function test_duplicate_identity_within_the_sheet_is_reported_once(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([
            ['CT-1001', 'Amrani Mohamed', 'Casablanca', null, null],
            ['CT-1001', 'AMRANI MOHAMED', 'Rabat', null, null], // même clé métier normalisée
        ]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(1, ProductionRecord::count());
        $this->assertSame(1, $response->json('data.sync.created_count'));
        $this->assertSame(1, $response->json('data.sync.failed_count'));
        $this->assertStringContainsString('Clé métier identique', $response->json('data.sync.row_errors.0.reason'));
    }

    public function test_missing_required_header_fails_without_writing_anything(): void
    {
        [, $token] = $this->actingAdmin();

        // Pas de colonne « Client » : la synchro doit être refusée, pas devinée.
        $this->fakeSheet(
            [['CT-1001', 'Casablanca', 'Auto', 'Actif']],
            ['N° contrat', 'Ville', 'Produit', 'Statut'],
        );

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('client_name', $response->json('message'));
        $this->assertSame(0, ProductionRecord::count());

        $sync = ProductionSync::latest('id')->first();
        $this->assertSame(ProductionSync::STATUS_FAILED, $sync->status);
        $this->assertNotNull($sync->error_summary);
    }

    public function test_google_api_error_produces_an_actionable_failure(): void
    {
        [, $token] = $this->actingAdmin();

        $this->failReaderWith(GoogleSheetsException::forbidden());

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('403', $response->json('message'));
        $this->assertStringContainsString('compte de service', $response->json('message'));

        $sync = ProductionSync::latest('id')->first();
        $this->assertSame(ProductionSync::STATUS_FAILED, $sync->status);
        $this->assertSame(0, ProductionRecord::count());
    }

    public function test_missing_google_configuration_produces_a_clear_failure(): void
    {
        [, $token] = $this->actingAdmin();

        // Aucun lecteur simulé : le vrai client est utilisé, sans identifiants
        // configurés. Aucun appel réseau n'est effectué (échec en amont).
        config([
            'production.spreadsheet_id' => null,
            'production.credentials_path' => null,
        ]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('GOOGLE_SHEETS_SPREADSHEET_ID', $response->json('message'));
        $this->assertStringContainsString('GOOGLE_APPLICATION_CREDENTIALS', $response->json('message'));

        $this->assertSame(ProductionSync::STATUS_FAILED, ProductionSync::latest('id')->first()->status);
    }

    public function test_header_aliases_are_matched_case_and_accent_insensitively(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet(
            [['CT-2001', 'Zahra Idrissi', 'Fès', 'Santé', 'Actif']],
            ['N° CONTRAT', 'nom du client', 'VILLE', 'Produit', 'Statut'],
        );

        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $record = ProductionRecord::sole();
        $this->assertSame('CT-2001', $record->contract_reference);
        $this->assertSame('Zahra Idrissi', $record->client_name);
        $this->assertSame('Fès', $record->city);
    }

    public function test_rows_absent_from_a_later_sync_are_never_deleted(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([['CT-1', 'Un', null, null, null], ['CT-2', 'Deux', null, null, null]]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $this->fakeSheet([['CT-1', 'Un', null, null, null]]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $this->assertSame(2, ProductionRecord::count()); // CT-2 conservé
    }
}
