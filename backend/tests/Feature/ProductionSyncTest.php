<?php

namespace Tests\Feature;

use App\Models\ProductionRecord;
use App\Models\ProductionSync;
use App\Models\User;
use App\Services\Google\GoogleSheetsException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\InteractsWithProductionSheets;
use Tests\TestCase;

/**
 * Fixtures synthétiques : les noms, montants et dates ne correspondent à aucun
 * client réel. Aucun identifiant Google n'est utilisé.
 */
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

    public function test_sync_maps_all_eleven_columns_and_generates_a_reference(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([$this->sheetRow()]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(1, ProductionRecord::count());

        $record = ProductionRecord::sole();
        $this->assertSame('2026-10-01', $record->date->toDateString());
        $this->assertSame('0123456', $record->cial); // zéro initial conservé
        $this->assertSame('FOURRIQUES', $record->client_name); // NOM
        $this->assertSame('Danielle', $record->first_name); // PRENOM
        $this->assertSame('91.55', $record->cotisation);
        $this->assertSame('1098.6', $record->premium_annual);
        $this->assertSame('KIASSURE', $record->cie);
        $this->assertSame('COVER UP - N3', $record->product);
        $this->assertSame('2026-12-01', $record->date_effet->toDateString());
        $this->assertSame('CHLOE', $record->validation);
        $this->assertSame('SMS', $record->type_signature);

        $this->assertNull($record->phone, 'La feuille ne doit jamais renseigner le téléphone.');
        $this->assertSame(2, $record->source_row); // en-tête = ligne 1
        $this->assertNotNull($record->last_synced_at);

        // Référence générée, jamais issue de la feuille.
        $this->assertMatchesRegularExpression('/^CTR-\d{4}-[A-Z0-9]{8}$/', (string) $record->reference_contrat);
        // Compatibilité téléphonie : l'attribut historique renvoie la référence.
        $this->assertSame($record->reference_contrat, $record->contract_reference);

        // Colonnes historiques plus jamais alimentées par la feuille.
        $this->assertNull($record->city);
        $this->assertNull($record->status);

        $sync = ProductionSync::latest('id')->first();
        $this->assertSame(ProductionSync::STATUS_COMPLETED, $sync->status);
        $this->assertSame(1, $sync->rows_read);
        $this->assertSame(1, $sync->created_count);
        $this->assertSame(0, $sync->updated_count);
        $this->assertSame(0, $sync->failed_count);
        $this->assertNull($sync->warnings);

        $this->assertSame('completed', $response->json('data.sync.status'));
        $this->assertSame(1, $response->json('data.sync.created_count'));
    }

    public function test_re_syncing_the_same_sheet_does_not_create_duplicates_and_keeps_the_reference(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([$this->sheetRow()]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();
        $reference = ProductionRecord::sole()->reference_contrat;

        $this->fakeSheet([$this->sheetRow()]);
        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(1, ProductionRecord::count());
        $this->assertSame(0, $response->json('data.sync.created_count'));
        $this->assertSame(1, $response->json('data.sync.updated_count'));
        $this->assertSame($reference, ProductionRecord::sole()->reference_contrat);
    }

    public function test_re_sync_updates_amounts_and_validation_without_changing_the_reference(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([$this->sheetRow()]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();
        $record = ProductionRecord::sole();
        $reference = $record->reference_contrat;

        $this->fakeSheet([$this->sheetRow([
            'COTISATION' => '95.00',
            'PRIME ANNUELLE' => '1200',
            'VALIDATION' => 'CARINE',
            'TYPE DE SIGNATURE' => 'MAIL',
        ])]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $record->refresh();
        $this->assertSame(1, ProductionRecord::count());
        $this->assertSame('95.00', $record->cotisation);
        $this->assertSame('1200', $record->premium_annual);
        $this->assertSame('CARINE', $record->validation);
        $this->assertSame('MAIL', $record->type_signature);
        $this->assertSame($reference, $record->reference_contrat);
    }

    public function test_column_order_and_header_whitespace_do_not_break_mapping(): void
    {
        [, $token] = $this->actingAdmin();

        $headers = [
            ' NOM ',
            'DATE',
            "Date d\u{2019}effet", // apostrophe typographique
            "PRIME\u{00A0}ANNUELLE", // espace insécable
            'CIAL',
            'PRENOM',
            'COTISATION',
            'CIE',
            'PRODUIT',
            'VALIDATION',
            ' TYPE   DE SIGNATURE ',
        ];

        $row = $this->sheetRowFor($headers, ['CIAL' => '00042']);

        $this->fakeSheet([$row], $headers);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $record = ProductionRecord::sole();
        $this->assertSame('00042', $record->cial); // zéros initiaux intacts
        $this->assertSame('FOURRIQUES', $record->client_name);
        $this->assertSame('2026-10-01', $record->date->toDateString());
        $this->assertSame('2026-12-01', $record->date_effet->toDateString());
        $this->assertSame('1098.6', $record->premium_annual);
        $this->assertSame('SMS', $record->type_signature);
    }

    public function test_missing_required_header_aborts_without_writing(): void
    {
        [, $token] = $this->actingAdmin();

        $headers = $this->sheetHeaders();
        array_splice($headers, 2, 1); // retire NOM

        $this->fakeSheet([$this->sheetRow()], $headers);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('NOM', $response->json('message'));
        $this->assertStringContainsString('Aucune donnée n\'a été modifiée', $response->json('message'));
        $this->assertSame(0, ProductionRecord::count());
        $this->assertSame(ProductionSync::STATUS_FAILED, ProductionSync::latest('id')->first()->status);
    }

    public function test_duplicate_required_header_aborts_without_writing(): void
    {
        [, $token] = $this->actingAdmin();

        $headers = $this->sheetHeaders();
        $headers[] = 'NOM'; // doublon

        $this->fakeSheet([array_merge($this->sheetRow(), ['DOUBLON'])], $headers);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('plusieurs fois', $response->json('message'));
        $this->assertSame(0, ProductionRecord::count());
    }

    public function test_trailing_empty_columns_are_ignored_without_warning(): void
    {
        [, $token] = $this->actingAdmin();

        $headers = [...$this->sheetHeaders(), '', '   '];
        $this->fakeSheet([[...$this->sheetRow(), null, null]], $headers);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(1, ProductionRecord::count());
        $this->assertNull($response->json('data.sync.warnings'));
    }

    public function test_unknown_populated_column_is_reported_and_not_imported(): void
    {
        [, $token] = $this->actingAdmin();

        $headers = [...$this->sheetHeaders(), 'REMARQUE INTERNE'];
        $this->fakeSheet([[...$this->sheetRow(), 'valeur non prévue']], $headers);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(1, ProductionRecord::count());
        $warnings = $response->json('data.sync.warnings');
        $this->assertIsArray($warnings);
        $this->assertStringContainsString('REMARQUE INTERNE', $warnings[0]);
        $this->assertStringContainsString('Aucune valeur', $warnings[0]);
    }

    public function test_invalid_dates_and_amounts_reject_the_row_without_zeroing_or_guessing(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([
            $this->sheetRow(['NOM' => 'VALIDE']),
            $this->sheetRow(['NOM' => 'DATE INVALIDE', 'DATE' => '31/02/2026']),
            $this->sheetRow(['NOM' => 'MONTANT INVALIDE', 'COTISATION' => 'N/A']),
            $this->sheetRow(['NOM' => 'MONTANT PRECIS', 'COTISATION' => '1.23456']),
            $this->sheetRow(['NOM' => 'MONTANT FR', 'PRIME ANNUELLE' => '1 098,60']),
        ]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(2, ProductionRecord::count());
        $this->assertSame('partial', $response->json('data.sync.status'));
        $this->assertSame(3, $response->json('data.sync.failed_count'));

        $errors = $response->json('data.sync.row_errors');
        $fields = array_column($errors, 'field');
        $this->assertContains('DATE', $fields);
        $this->assertContains('COTISATION', $fields);

        // Une valeur invalide rejette la ligne : ni 0, ni arrondi, ni import
        // partiel de la ligne.
        $this->assertNull(ProductionRecord::where('client_name', 'MONTANT INVALIDE')->first());
        $this->assertNull(ProductionRecord::where('client_name', 'MONTANT PRECIS')->first());
        $this->assertSame('1098.60', ProductionRecord::where('client_name', 'MONTANT FR')->value('premium_annual'));

        // Aucune donnée personnelle (ni valeur source) dans le diagnostic.
        $this->assertArrayNotHasKey('value', $errors[0]);
    }

    public function test_blank_and_incomplete_rows_follow_the_documented_policy(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([
            $this->sheetRow(['NOM' => 'VALIDE']),
            array_fill(0, 11, null), // ligne vide → ignorée
            array_fill(0, 11, '   '), // cellules vides → ignorée
            $this->sheetRow(['NOM' => null]), // NOM obligatoire → rejetée
        ]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(1, $response->json('data.sync.created_count'));
        $this->assertSame(2, $response->json('data.sync.skipped_count'));
        $this->assertSame(1, $response->json('data.sync.failed_count'));
        $this->assertSame(4, $response->json('data.sync.rows_read'));
        $this->assertSame('NOM', $response->json('data.sync.row_errors.0.field'));
        $this->assertSame('Champ obligatoire vide.', $response->json('data.sync.row_errors.0.reason'));
    }

    public function test_duplicate_identity_within_the_sheet_is_reported_once(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([
            $this->sheetRow(['COTISATION' => '91.55']),
            $this->sheetRow(['COTISATION' => '99.99']), // même identité, montant différent
        ]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame(1, ProductionRecord::count());
        $this->assertSame(1, $response->json('data.sync.failed_count'));
        $this->assertStringContainsString('identique à la ligne 2', $response->json('data.sync.row_errors.0.reason'));
    }

    public function test_inserting_and_reordering_sheet_rows_does_not_remap_existing_contracts(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([$this->sheetRow(['NOM' => 'EXISTANT', 'CIAL' => '00001'])]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();
        $original = ProductionRecord::sole();

        // Nouvelle ligne insérée avant, puis les deux lignes dans l'ordre inverse.
        $this->fakeSheet([
            $this->sheetRow(['NOM' => 'NOUVEAU', 'CIAL' => '00002', 'VALIDATION' => 'LARA']),
            $this->sheetRow(['NOM' => 'EXISTANT', 'CIAL' => '00001']),
        ]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $this->assertSame(2, ProductionRecord::count());
        $original->refresh();
        $this->assertSame('KIASSURE', $original->cie); // inchangé
        $this->assertSame('CHLOE', $original->validation);
        $this->assertSame('00001', $original->cial);
        $this->assertSame('EXISTANT', $original->client_name);
    }

    public function test_reference_is_unique_at_database_level_and_stable_across_retries(): void
    {
        [, $token] = $this->actingAdmin();

        $this->assertTrue(Schema::hasIndex('production_records', ['reference_contrat'], 'unique'));

        $this->fakeSheet([
            $this->sheetRow(['CIAL' => '00001']),
            $this->sheetRow(['CIAL' => '00002']),
        ]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $references = ProductionRecord::pluck('reference_contrat')->all();
        $this->assertCount(2, array_unique($references));

        foreach (range(1, 20) as $i) {
            ProductionRecord::createWithReference(['source_key' => hash('sha256', 'ref-fixture-'.$i)]);
        }

        $this->assertSame(22, ProductionRecord::distinct()->count('reference_contrat'));
        $this->assertSame(22, ProductionRecord::count());
    }

    public function test_migration_backfills_missing_references_without_touching_existing_ones(): void
    {
        DB::table('production_records')->insert([
            'source_key' => hash('sha256', 'legacy-sans-reference'),
            'client_name' => 'ANCIEN ENREGISTREMENT',
            'created_at' => '2024-05-06 10:00:00',
            'updated_at' => '2024-05-06 10:00:00',
        ]);
        $legacyId = (int) DB::table('production_records')->where('client_name', 'ANCIEN ENREGISTREMENT')->value('id');

        $withReference = ProductionRecord::createWithReference([
            'source_key' => hash('sha256', 'deja-reference'),
        ]);
        $existingReference = $withReference->reference_contrat;

        $migration = require database_path('migrations/2026_10_20_000001_align_production_records_with_sheet_schema.php');
        $migration->up();
        $migration->up(); // rejouable

        $this->assertSame(
            'CTR-2024-'.str_pad((string) $legacyId, 6, '0', STR_PAD_LEFT),
            DB::table('production_records')->where('id', $legacyId)->value('reference_contrat'),
        );
        $this->assertSame($existingReference, $withReference->refresh()->reference_contrat);
    }

    public function test_manually_entered_phone_survives_a_sync(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([$this->sheetRow()]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $record = ProductionRecord::sole();
        $this->patchJson("/api/production/{$record->id}/phone", ['phone' => '+212 6 12 34 56 78'], $this->authHeaders($token))
            ->assertOk()
            ->assertJsonPath('data.phone', '+212 6 12 34 56 78');

        $this->fakeSheet([$this->sheetRow(['VALIDATION' => 'CARINE'])]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $record->refresh();
        $this->assertSame('+212 6 12 34 56 78', $record->phone);
        $this->assertSame('212612345678', $record->phone_normalise);
        $this->assertSame('CARINE', $record->validation);
    }

    public function test_google_api_errors_produce_a_visible_failure_and_no_writes(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([$this->sheetRow()]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();
        $countBefore = ProductionRecord::count();

        $this->failReaderWith(GoogleSheetsException::forbidden());
        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('403', $response->json('message'));
        $this->assertSame($countBefore, ProductionRecord::count());

        $sync = ProductionSync::latest('id')->first();
        $this->assertSame(ProductionSync::STATUS_FAILED, $sync->status);
        $this->assertNotNull($sync->completed_at);
        $this->assertNotNull($sync->error_summary);
    }

    public function test_quota_errors_are_reported_distinctly(): void
    {
        [, $token] = $this->actingAdmin();

        $this->failReaderWith(GoogleSheetsException::rateLimited());
        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('429', $response->json('message'));
        $this->assertSame('failed', ProductionSync::latest('id')->first()->status);
    }

    public function test_truncated_range_is_reported_as_partial_not_success(): void
    {
        [, $token] = $this->actingAdmin();

        config(['production.range' => 'A1:Z3']); // en-tête + 2 lignes maximum

        $this->fakeSheet([
            $this->sheetRow(['CIAL' => '00001']),
            $this->sheetRow(['CIAL' => '00002']),
        ]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertCreated();
        $this->assertSame('partial', $response->json('data.sync.status'));
        $warnings = $response->json('data.sync.warnings');
        $this->assertIsArray($warnings);
        $this->assertStringContainsString('GOOGLE_SHEETS_RANGE', implode(' ', $warnings));
    }

    public function test_rows_absent_from_a_later_sync_are_never_deleted(): void
    {
        [, $token] = $this->actingAdmin();

        $this->fakeSheet([
            $this->sheetRow(['CIAL' => '00001']),
            $this->sheetRow(['CIAL' => '00002']),
        ]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $this->fakeSheet([$this->sheetRow(['CIAL' => '00001'])]);
        $this->postJson('/api/production/sync', [], $this->authHeaders($token))->assertCreated();

        $this->assertSame(2, ProductionRecord::count());
    }

    public function test_header_row_missing_is_reported_safely(): void
    {
        [, $token] = $this->actingAdmin();

        $this->bindReader([[], ['   ']]);

        $response = $this->postJson('/api/production/sync', [], $this->authHeaders($token));

        $response->assertStatus(422);
        $this->assertStringContainsString('en-têtes', $response->json('message'));
        $this->assertSame(0, ProductionRecord::count());
    }
}
