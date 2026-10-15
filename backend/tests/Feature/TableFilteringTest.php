<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DuplicateExtraction;
use App\Models\DuplicateExtractionItem;
use App\Models\ImportedFile;
use App\Models\ImportHistory;
use App\Models\User;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Filtres, tris et pagination côté serveur pour chaque table de
 * l'application : fiches, contacts d'une fiche, historique d'import,
 * extractions, doublons et administrateurs.
 */
class TableFilteringTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;
    private User $otherAdmin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name' => 'Admin Principal',
            'email' => 'principal@test.ma',
            'password' => 'motdepasse1',
            'role' => 'admin',
            'active' => true,
        ]);
        $this->otherAdmin = User::create([
            'name' => 'Zoe Secondaire',
            'email' => 'zoe@test.ma',
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

    /* ------------------------------------------------------------- fichiers */

    public function test_files_can_be_filtered_and_sorted(): void
    {
        $a = $this->makeFile('alpha.xlsx', ['status' => ImportedFile::STATUS_IMPORTED, 'successful_rows' => 10, 'total_rows' => 10]);
        $this->makeFile('beta.xlsx', ['status' => ImportedFile::STATUS_FAILED, 'successful_rows' => 0, 'failed_rows' => 5]);
        $c = $this->makeFile('gamma.xlsx', ['imported_by' => $this->otherAdmin->id, 'successful_rows' => 3, 'total_rows' => 3]);
        $a->forceFill(['created_at' => now()->subDays(20)])->save();

        // Recherche par nom de fichier.
        $this->assertSame(1, $this->getJson('/api/files?search=beta', $this->headers())->json('meta.total'));

        // Filtre statut.
        $failed = $this->getJson('/api/files?status=failed', $this->headers())->json('data');
        $this->assertCount(1, $failed);

        // Filtre administrateur importateur.
        $byAdmin = $this->getJson("/api/files?imported_by={$this->otherAdmin->id}", $this->headers());
        $this->assertSame(1, $byAdmin->json('meta.total'));
        $this->assertSame($c->id, $byAdmin->json('data.0.id'));

        // Plage de dates sur l'import.
        $oldOnly = $this->getJson('/api/files?imported_to='.now()->subDays(10)->toDateString(), $this->headers());
        $this->assertSame(1, $oldOnly->json('meta.total'));
        $this->assertSame('alpha.xlsx', $oldOnly->json('data.0.original_filename'));

        // Tri par nom et par nombre de lignes réussies.
        $byName = $this->getJson('/api/files?sort_by=original_filename&sort_direction=asc', $this->headers());
        $this->assertSame(['alpha.xlsx', 'beta.xlsx', 'gamma.xlsx'], array_column($byName->json('data'), 'original_filename'));

        // Les filtres sont conservés quand on change le tri.
        $kept = $this->getJson('/api/files?search=beta&sort_by=original_filename', $this->headers());
        $this->assertSame(1, $kept->json('meta.total'));
    }

    public function test_files_reject_unknown_sort_field(): void
    {
        $this->makeFile('x.xlsx');
        $this->getJson('/api/files?sort_by=original_filename;--', $this->headers())->assertStatus(422);
        $this->assertSame(1, ImportedFile::count());
    }

    /* -------------------------------------------------- contacts d'une fiche */

    public function test_file_contacts_can_be_filtered_and_sorted(): void
    {
        $file = $this->makeFile('contacts.xlsx');
        $this->makeContact($file, ['nom' => 'ALAMI', 'prenom' => 'Sara', 'tel' => '0611111111', 'email' => 'sara@x.ma', 'ville' => 'RABAT', 'cp' => '10000']);
        $this->makeContact($file, ['nom' => 'BOUZID', 'prenom' => 'Omar', 'tel' => '0622222222', 'email' => 'omar@x.ma', 'ville' => 'FES', 'cp' => '30000']);
        $this->makeContact($file, ['nom' => 'CHAKIR', 'prenom' => 'Amina', 'tel' => '0633333333', 'email' => 'amina@x.ma', 'ville' => 'RABAT', 'cp' => '10000']);

        $base = "/api/files/{$file->id}/contacts";

        $this->assertSame(3, $this->getJson($base, $this->headers())->json('meta.total'));

        // Filtres colonne.
        $this->assertSame(1, $this->getJson("{$base}?tel=0622", $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?email=omar@", $this->headers())->json('meta.total'));
        $this->assertSame(2, $this->getJson("{$base}?ville=RABAT", $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?cp=30000", $this->headers())->json('meta.total'));

        // Recherche groupée + filtre colonne (AND).
        $combined = $this->getJson("{$base}?search=amina&ville=RABAT", $this->headers());
        $this->assertSame(1, $combined->json('meta.total'));
        $this->assertSame('CHAKIR', $combined->json('data.0.nom'));

        // Tri décroissant sur le nom.
        $sorted = $this->getJson("{$base}?sort_by=nom&sort_direction=desc", $this->headers());
        $this->assertSame(['CHAKIR', 'BOUZID', 'ALAMI'], array_column($sorted->json('data'), 'nom'));

        // Pagination : filtres conservés d'une page à l'autre.
        $paged = $this->getJson("{$base}?ville=RABAT&per_page=1&page=2", $this->headers());
        $this->assertSame(2, $paged->json('meta.total'));
        $this->assertSame(2, $paged->json('meta.current_page'));
    }

    public function test_file_contacts_default_to_active_only(): void
    {
        $file = $this->makeFile('actifs.xlsx');
        $kept = $this->makeContact($file, ['nom' => 'GARDE']);
        $extracted = $this->makeContact($file, ['nom' => 'EXTRAIT']);

        $extraction = DuplicateExtraction::create([
            'source_file_id' => $file->id,
            'user_id' => $this->admin->id,
            'extracted_count' => 1,
            'status' => DuplicateExtraction::STATUS_COMPLETED,
        ]);
        DuplicateExtractionItem::create([
            'duplicate_extraction_id' => $extraction->id,
            'contact_id' => $extracted->id,
            'original_file_id' => $file->id,
        ]);

        $response = $this->getJson("/api/files/{$file->id}/contacts", $this->headers());
        $this->assertSame(1, $response->json('meta.total'));
        $this->assertSame($kept->nom, $response->json('data.0.nom'));
    }

    public function test_filtered_cleaned_export_matches_the_filtered_dataset(): void
    {
        $file = $this->makeFile('export-filtre.xlsx');
        $this->makeContact($file, ['nom' => 'RABAT1', 'ville' => 'RABAT']);
        $this->makeContact($file, ['nom' => 'RABAT2', 'ville' => 'RABAT']);
        $this->makeContact($file, ['nom' => 'FES1', 'ville' => 'FES']);

        $response = $this->get("/api/files/{$file->id}/cleaned-export?ville=RABAT", $this->headers());
        $response->assertOk();

        $sheet = $this->readXlsx($response->streamedContent());
        $this->assertCount(3, $sheet); // en-tête + 2 contacts de Rabat
        $this->assertSame(['RABAT1', 'RABAT2'], array_column(array_slice($sheet, 1), 0));
    }

    /* ----------------------------------------------------- historique import */

    public function test_import_history_filters_and_sorting(): void
    {
        $file = $this->makeFile('hist.xlsx');
        ImportHistory::create([
            'imported_file_id' => $file->id, 'user_id' => $this->admin->id,
            'original_filename' => 'hist.xlsx', 'status' => ImportHistory::STATUS_COMPLETED,
            'total_rows' => 10, 'successful_rows' => 10, 'failed_rows' => 0, 'skipped_rows' => 0,
        ]);
        ImportHistory::create([
            'imported_file_id' => null, 'user_id' => $this->otherAdmin->id,
            'original_filename' => 'echec.xlsx', 'status' => ImportHistory::STATUS_FAILED,
            'total_rows' => 5, 'successful_rows' => 1, 'failed_rows' => 4, 'skipped_rows' => 0,
        ]);

        $base = '/api/import-history';
        $this->assertSame(2, $this->getJson($base, $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?search=echec", $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?status=failed", $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?user_id={$this->otherAdmin->id}", $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?failed_min=3", $this->headers())->json('meta.total'));
        $this->assertSame(0, $this->getJson("{$base}?failed_min=10", $this->headers())->json('meta.total'));

        // Recherche + statut combinés : ne doit jamais élargir le périmètre.
        $this->assertSame(0, $this->getJson("{$base}?search=hist&status=failed", $this->headers())->json('meta.total'));

        // Tri par lignes échouées.
        $sorted = $this->getJson("{$base}?sort_by=failed_rows&sort_direction=desc", $this->headers());
        $this->assertSame('echec.xlsx', $sorted->json('data.0.original_filename'));

        $this->getJson("{$base}?sort_by=drop table", $this->headers())->assertStatus(422);
    }

    /* --------------------------------------------------------- extractions */

    public function test_extractions_filters_and_sorting(): void
    {
        $fileA = $this->makeFile('fiche-a.xlsx');
        $fileB = $this->makeFile('fiche-b.xlsx');
        $c1 = $this->makeContact($fileA, ['nom' => 'DUPONT', 'prenom' => 'Jean']);
        $c2 = $this->makeContact($fileB, ['nom' => 'MARTIN', 'prenom' => 'Claire']);

        $this->extract($fileA, $c1, $this->admin, DuplicateExtraction::STATUS_COMPLETED);
        $this->extract($fileB, $c2, $this->otherAdmin, DuplicateExtraction::STATUS_RESTORED);

        $base = '/api/duplicate-extractions';
        $this->assertSame(2, $this->getJson($base, $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?status=completed", $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?status=restored", $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?source_file_id={$fileA->id}", $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?user_id={$this->otherAdmin->id}", $this->headers())->json('meta.total'));

        // Recherche sur le contact extrait.
        $byContact = $this->getJson("{$base}?contact=dupont", $this->headers());
        $this->assertSame(1, $byContact->json('meta.total'));

        // Tri par fiche source et par administrateur.
        $bySource = $this->getJson("{$base}?sort_by=source_file&sort_direction=asc", $this->headers());
        $this->assertSame('fiche-a.xlsx', $bySource->json('data.0.source_file_name'));
        $byAdmin = $this->getJson("{$base}?sort_by=performed_by&sort_direction=asc", $this->headers());
        $this->assertSame('Admin Principal', $byAdmin->json('data.0.performed_by'));

        $this->getJson("{$base}?sort_by=evil", $this->headers())->assertStatus(422);
    }

    /* ------------------------------------------------------------- doublons */

    public function test_duplicate_groups_filters(): void
    {
        $file = $this->makeFile('doublons.xlsx');
        $this->makeContact($file, ['nom' => 'ALPHA', 'prenom' => 'Un', 'tel' => '0612345678']);
        $this->makeContact($file, ['nom' => 'BETA', 'prenom' => 'Deux', 'tel' => '06 12 34 56 78']);
        $this->makeContact($file, ['nom' => 'GAMMA', 'prenom' => 'Trois', 'tel' => '06-12-34-56-78']);
        $this->makeContact($file, ['nom' => 'DELTA', 'prenom' => 'Quatre', 'tel' => '0699999999']);
        $this->makeContact($file, ['nom' => 'EPSILON', 'prenom' => 'Cinq', 'tel' => '0699999999']);

        $base = "/api/files/{$file->id}/duplicates";
        $all = $this->getJson($base, $this->headers());
        $this->assertSame(2, $all->json('meta.total')); // deux groupes

        // Filtre par numéro (formatage libre).
        $byPhone = $this->getJson("{$base}?phone=06%2012%2034%2056%2078", $this->headers());
        $this->assertSame(1, $byPhone->json('meta.total'));
        $this->assertSame('3', (string) count($byPhone->json('data.0.contacts')));

        // Filtre par nom de contact.
        $byName = $this->getJson("{$base}?name=epsilon", $this->headers());
        $this->assertSame(1, $byName->json('meta.total'));
        $this->assertSame('0699999999', $byName->json('data.0.phone'));

        // Taille minimale de groupe.
        $this->assertSame(2, $this->getJson("{$base}?min_contacts=2", $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?min_contacts=3", $this->headers())->json('meta.total'));
        $this->assertSame(0, $this->getJson("{$base}?min_contacts=4", $this->headers())->json('meta.total'));
    }

    public function test_duplicate_scan_distinguishes_active_and_extracted(): void
    {
        $file = $this->makeFile('doublons-extraits.xlsx');
        $a = $this->makeContact($file, ['nom' => 'A', 'tel' => '0612345678']);
        $b = $this->makeContact($file, ['nom' => 'B', 'tel' => '0612345678']);

        $this->extract($file, $b, $this->admin, DuplicateExtraction::STATUS_COMPLETED);

        $response = $this->getJson("/api/files/{$file->id}/duplicates", $this->headers());
        $this->assertSame(1, $response->json('stats.extracted_contacts'));
        $this->assertSame(1, $response->json('stats.total_active'));
        $this->assertSame(0, $response->json('meta.total')); // plus de doublon actif
        $this->assertNotNull($a->id);
    }

    /* ---------------------------------------------------- administrateurs */

    public function test_admins_filters_and_sorting(): void
    {
        $base = '/api/admins';
        $this->assertSame(2, $this->getJson($base, $this->headers())->json('meta.total'));

        $this->assertSame(1, $this->getJson("{$base}?search=zoe", $this->headers())->json('meta.total'));
        $this->assertSame(0, $this->getJson("{$base}?search=zoe&active=0", $this->headers())->json('meta.total'));

        // Suspension puis filtre de statut.
        $this->otherAdmin->forceFill(['active' => false])->save();
        $this->assertSame(1, $this->getJson("{$base}?active=0", $this->headers())->json('meta.total'));
        $this->assertSame(1, $this->getJson("{$base}?active=1", $this->headers())->json('meta.total'));

        $sorted = $this->getJson("{$base}?sort_by=name&sort_direction=desc", $this->headers());
        $this->assertSame('Zoe Secondaire', $sorted->json('data.0.name'));

        $this->getJson("{$base}?sort_by=password", $this->headers())->assertStatus(422);
    }

    private function extract(ImportedFile $file, Contact $contact, User $user, string $status): DuplicateExtraction
    {
        $extraction = DuplicateExtraction::create([
            'source_file_id' => $file->id,
            'user_id' => $user->id,
            'extracted_count' => 1,
            'status' => $status,
        ]);

        if ($status === DuplicateExtraction::STATUS_COMPLETED) {
            DuplicateExtractionItem::create([
                'duplicate_extraction_id' => $extraction->id,
                'contact_id' => $contact->id,
                'original_file_id' => $file->id,
            ]);
        }

        return $extraction;
    }
}
