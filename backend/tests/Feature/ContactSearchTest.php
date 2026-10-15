<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DuplicateExtraction;
use App\Models\DuplicateExtractionItem;
use App\Models\ImportedFile;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Recherche globale de contacts (GET /api/contact-search) : recherche
 * multicolonne, filtres combinés, statut actif/extrait, tri, pagination,
 * autorisation et export filtré.
 */
class ContactSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name' => 'Admin Search',
            'email' => 'search@test.ma',
            'password' => 'motdepasse1',
            'role' => 'admin',
            'active' => true,
        ]);
        $this->token = $this->admin->createToken('session')->plainTextToken;
    }

    private function headers(): array
    {
        return ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'];
    }

    private function makeFile(string $name, array $attrs = []): ImportedFile
    {
        return ImportedFile::create(array_merge([
            'original_filename' => $name,
            'storage_path' => null,
            'file_size' => 128,
            'file_hash' => hash('sha256', $name.'-'.uniqid('', true)),
            'status' => ImportedFile::STATUS_IMPORTED,
            'imported_by' => $this->admin->id,
            'total_rows' => 0,
            'successful_rows' => 0,
            'failed_rows' => 0,
            'skipped_rows' => 0,
        ], $attrs));
    }

    private function makeContact(ImportedFile $file, array $attrs = []): Contact
    {
        $tel = $attrs['tel'] ?? null;

        return Contact::create(array_merge([
            'imported_file_id' => $file->id,
            'nom' => 'NOM',
            'prenom' => 'Prenom',
            'adresse' => null,
            'cp' => null,
            'ville' => null,
            'tel' => $tel,
            'telephone_normalise' => PhoneNormalizer::normalize($tel),
            'email' => null,
            'date_naissance' => null,
        ], $attrs));
    }

    private function extract(ImportedFile $file, Contact $contact): DuplicateExtraction
    {
        $extraction = DuplicateExtraction::create([
            'source_file_id' => $file->id,
            'user_id' => $this->admin->id,
            'extracted_count' => 1,
            'status' => DuplicateExtraction::STATUS_COMPLETED,
        ]);
        DuplicateExtractionItem::create([
            'duplicate_extraction_id' => $extraction->id,
            'contact_id' => $contact->id,
            'original_file_id' => $file->id,
        ]);

        return $extraction;
    }

    private function readXlsx(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'read_');
        file_put_contents($path, $bytes);
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $data = $spreadsheet->getActiveSheet()->toArray();
        $spreadsheet->disconnectWorksheets();
        unlink($path);

        return $data;
    }

    public function test_endpoint_requires_authentication(): void
    {
        $this->getJson('/api/contact-search')->assertUnauthorized();
    }

    public function test_empty_search_returns_all_active_contacts_paginated(): void
    {
        $file = $this->makeFile('fiche-a.xlsx');
        foreach (range(1, 3) as $i) {
            $this->makeContact($file, ['nom' => "Nom{$i}"]);
        }

        $response = $this->getJson('/api/contact-search?per_page=2', $this->headers())->assertOk();
        $this->assertSame(3, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.last_page'));
        $this->assertCount(2, $response->json('data'));
    }

    public function test_search_by_name_is_partial_and_case_insensitive(): void
    {
        $file = $this->makeFile('fiche-noms.xlsx');
        $this->makeContact($file, ['nom' => 'EL AMRANI', 'prenom' => 'Mohammed']);
        $this->makeContact($file, ['nom' => 'BENALI', 'prenom' => 'Ahmed']);

        $response = $this->getJson('/api/contact-search?q=amr', $this->headers())->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('EL AMRANI', $response->json('data.0.nom'));

        // Recherche par prénom.
        $byPrenom = $this->getJson('/api/contact-search?q=ahmed', $this->headers())->assertOk();
        $this->assertSame(1, $byPrenom->json('meta.total'));
    }

    public function test_search_by_telephone_handles_formatting_differences(): void
    {
        $file = $this->makeFile('fiche-tel.xlsx');
        $this->makeContact($file, ['nom' => 'TEL', 'tel' => '0612345678']);
        $this->makeContact($file, ['nom' => 'AUTRE', 'tel' => '0699999999']);

        // Saisie formatée : la valeur stockée n'est pas modifiée mais la forme
        // normalisée est comparée.
        $formatted = $this->getJson('/api/contact-search?q=06%2012%2034%2056%2078', $this->headers())->assertOk();
        $this->assertSame(1, $formatted->json('meta.total'));
        $this->assertSame('0612345678', $formatted->json('data.0.tel'));

        // Préfixe numérique.
        $partial = $this->getJson('/api/contact-search?q=0612', $this->headers())->assertOk();
        $this->assertSame(1, $partial->json('meta.total'));
    }

    public function test_search_by_email_city_and_postal_code(): void
    {
        $file = $this->makeFile('fiche-fields.xlsx');
        $this->makeContact($file, ['nom' => 'A', 'email' => 'amina@example.com', 'ville' => 'CASABLANCA', 'cp' => '20000']);
        $this->makeContact($file, ['nom' => 'B', 'email' => 'karim@example.com', 'ville' => 'RABAT', 'cp' => '10000']);

        $this->assertSame(1, $this->getJson('/api/contact-search?q=amina@', $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/contact-search?q=casa', $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/contact-search?q=10000', $this->headers())->json('meta.total'));

        // Filtres ville / CP.
        $this->assertSame(1, $this->getJson('/api/contact-search?ville=casa', $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson('/api/contact-search?cp=200', $this->headers())->json('meta.total'));
    }

    public function test_search_by_source_filename(): void
    {
        $fileA = $this->makeFile('clients-marrakech.xlsx');
        $fileB = $this->makeFile('clients-rabat.xlsx');
        $this->makeContact($fileA, ['nom' => 'A']);
        $this->makeContact($fileB, ['nom' => 'B']);

        $response = $this->getJson('/api/contact-search?q=marrakech', $this->headers())->assertOk();
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('clients-marrakech.xlsx', $response->json('data.0.source_file.original_filename'));
    }

    public function test_search_spans_multiple_fiches_and_reports_source(): void
    {
        $fileA = $this->makeFile('un.xlsx');
        $fileB = $this->makeFile('deux.xlsx');
        $this->makeContact($fileA, ['nom' => 'PARTAGE', 'prenom' => 'Un']);
        $this->makeContact($fileB, ['nom' => 'PARTAGE', 'prenom' => 'Deux']);

        $response = $this->getJson('/api/contact-search?q=partage', $this->headers())->assertOk();
        $this->assertSame(2, $response->json('meta.total'));
        $files = array_column($response->json('data'), 'source_file');
        $this->assertEqualsCanonicalizing(['un.xlsx', 'deux.xlsx'], array_column($files, 'original_filename'));
    }

    public function test_combined_filters_are_intersected(): void
    {
        $fileA = $this->makeFile('cible.xlsx');
        $fileB = $this->makeFile('autre.xlsx');
        // Bon téléphone, bonne fiche, bonne ville.
        $this->makeContact($fileA, ['nom' => 'MATCH', 'tel' => '0612345678', 'ville' => 'FES']);
        // Même téléphone mais mauvaise fiche.
        $this->makeContact($fileB, ['nom' => 'AUTRE_FICHE', 'tel' => '0612345678', 'ville' => 'FES']);
        // Même fiche et téléphone mais mauvaise ville.
        $this->makeContact($fileA, ['nom' => 'AUTRE_VILLE', 'tel' => '0612345678', 'ville' => 'RABAT']);

        $response = $this->getJson(
            "/api/contact-search?q=0612345678&file_id={$fileA->id}&ville=FES",
            $this->headers(),
        )->assertOk();

        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame('MATCH', $response->json('data.0.nom'));
    }

    public function test_default_status_excludes_extracted_but_extracted_are_reachable(): void
    {
        $file = $this->makeFile('fiche-extraction.xlsx');
        $kept = $this->makeContact($file, ['nom' => 'GARDE']);
        $extracted = $this->makeContact($file, ['nom' => 'EXTRAIT']);
        $this->extract($file, $extracted);

        $default = $this->getJson('/api/contact-search', $this->headers())->assertOk();
        $this->assertSame(1, $default->json('meta.total'));
        $this->assertSame('GARDE', $default->json('data.0.nom'));
        $this->assertSame('active', $default->json('data.0.status'));

        $onlyExtracted = $this->getJson('/api/contact-search?status=extracted', $this->headers())->assertOk();
        $this->assertSame(1, $onlyExtracted->json('meta.total'));
        $this->assertSame('EXTRAIT', $onlyExtracted->json('data.0.nom'));
        $this->assertSame('extracted', $onlyExtracted->json('data.0.status'));

        $all = $this->getJson('/api/contact-search?status=all', $this->headers())->assertOk();
        $this->assertSame(2, $all->json('meta.total'));

        // Les contacts extraits ne sont pas comptés comme actifs.
        $this->assertSame($kept->id, $default->json('data.0.id'));
    }

    public function test_sorting_is_validated_and_applied(): void
    {
        $file = $this->makeFile('tri.xlsx');
        $this->makeContact($file, ['nom' => 'CHARLIE']);
        $this->makeContact($file, ['nom' => 'ALPHA']);
        $this->makeContact($file, ['nom' => 'BRAVO']);

        $asc = $this->getJson('/api/contact-search?sort_by=nom&sort_direction=asc', $this->headers())->assertOk();
        $this->assertSame(['ALPHA', 'BRAVO', 'CHARLIE'], array_column($asc->json('data'), 'nom'));

        $desc = $this->getJson('/api/contact-search?sort_by=nom&sort_direction=desc', $this->headers())->assertOk();
        $this->assertSame(['CHARLIE', 'BRAVO', 'ALPHA'], array_column($desc->json('data'), 'nom'));

        // Champ de tri inconnu : refusé, jamais injecté en SQL.
        $this->getJson('/api/contact-search?sort_by=nom;DROP TABLE contacts', $this->headers())->assertStatus(422);
        $this->getJson('/api/contact-search?sort_direction=sideways', $this->headers())->assertStatus(422);
        $this->assertSame(3, Contact::count());
    }

    public function test_import_date_range_filter(): void
    {
        $old = $this->makeFile('ancien.xlsx');
        $old->forceFill(['created_at' => now()->subDays(30)])->save();
        $recent = $this->makeFile('recent.xlsx');
        $this->makeContact($old, ['nom' => 'ANCIEN']);
        $this->makeContact($recent, ['nom' => 'RECENT']);

        $range = $this->getJson(
            '/api/contact-search?imported_from='.now()->subDays(7)->toDateString(),
            $this->headers(),
        )->assertOk();
        $this->assertSame(1, $range->json('meta.total'));
        $this->assertSame('RECENT', $range->json('data.0.nom'));

        // Plage sans correspondance.
        $empty = $this->getJson(
            '/api/contact-search?imported_to='.now()->subDays(60)->toDateString(),
            $this->headers(),
        )->assertOk();
        $this->assertSame(0, $empty->json('meta.total'));
    }

    public function test_extraction_date_range_filter(): void
    {
        $file = $this->makeFile('extract-range.xlsx');
        $contact = $this->makeContact($file, ['nom' => 'EXTRAIT']);
        $extraction = $this->extract($file, $contact);
        $extraction->forceFill(['created_at' => now()->subDays(10)])->save();

        $within = $this->getJson(
            '/api/contact-search?status=extracted&extracted_from='.now()->subDays(15)->toDateString(),
            $this->headers(),
        )->assertOk();
        $this->assertSame(1, $within->json('meta.total'));

        $outside = $this->getJson(
            '/api/contact-search?status=extracted&extracted_from='.now()->subDays(5)->toDateString(),
            $this->headers(),
        )->assertOk();
        $this->assertSame(0, $outside->json('meta.total'));
    }

    public function test_pagination_preserves_filters(): void
    {
        $file = $this->makeFile('page.xlsx');
        foreach (range(1, 5) as $i) {
            $this->makeContact($file, ['nom' => "CIBLE{$i}", 'ville' => 'TANGER']);
        }
        $this->makeContact($file, ['nom' => 'HORS', 'ville' => 'AGADIR']);

        $response = $this->getJson('/api/contact-search?ville=TANGER&per_page=2&page=2', $this->headers())->assertOk();
        $this->assertSame(5, $response->json('meta.total'));
        $this->assertSame(2, $response->json('meta.current_page'));
        $this->assertCount(2, $response->json('data'));
    }

    public function test_search_does_not_leak_contacts_of_archived_fiches(): void
    {
        $file = $this->makeFile('archivee.xlsx');
        $this->makeContact($file, ['nom' => 'ARCHIVE']);
        $file->delete(); // archivage (soft delete)

        $response = $this->getJson('/api/contact-search?status=all', $this->headers())->assertOk();
        $this->assertSame(0, $response->json('meta.total'));
    }

    public function test_filtered_export_contains_every_matching_record(): void
    {
        $file = $this->makeFile('export.xlsx');
        foreach (range(1, 4) as $i) {
            $this->makeContact($file, ['nom' => "EXPORT{$i}", 'ville' => 'OUJDA']);
        }
        $this->makeContact($file, ['nom' => 'HORS_SCOPE', 'ville' => 'NADOR']);

        $response = $this->get("/api/contact-search/export?ville=OUJDA&per_page=2", $this->headers());
        $response->assertOk()->assertHeader(
            'Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        );

        $sheet = $this->readXlsx($response->streamedContent());
        // En-tête + 4 lignes, alors même que per_page=2 : l'export n'est pas
        // limité à la page courante.
        $this->assertCount(5, $sheet);
        $this->assertSame('NOM', $sheet[0][0]);
    }
}
