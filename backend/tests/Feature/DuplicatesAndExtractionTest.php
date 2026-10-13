<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\DuplicateExtraction;
use App\Models\ExtractionRestoration;
use App\Models\ImportedFile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\CreatesXlsx;

class DuplicatesAndExtractionTest extends TestCase
{
    use RefreshDatabase, CreatesXlsx;

    private User $admin;
    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = User::create([
            'name' => 'Admin Dup',
            'email' => 'admin@test.ma',
            'password' => 'motdepasse1',
            'role' => 'admin',
            'active' => true,
        ]);
        $this->token = $this->admin->createToken('session')->plainTextToken;
    }

    private function importContacts(array $rows): ImportedFile
    {
        $this->post('/api/files/import', [
            'file' => $this->uploadedFrom(
                $this->writeXlsx(array_merge([$this->headersRow()], $rows), 'fiche.xlsx'),
            ),
        ], ['Authorization' => "Bearer {$this->token}", 'Accept' => 'application/json'])->assertCreated();

        return ImportedFile::latest('id')->first();
    }

    public function test_identical_and_harmlessly_formatted_numbers_are_grouped(): void
    {
        $file = $this->importContacts([
            ['EL AMRANI', 'MOHAMMED', null, null, null, 's:0612345678', null, null],
            ['BENALI', 'AHMED', null, null, null, 's:06 12 34 56 78', null, null],
            ['Tazi', 'Sara', null, null, null, 's:06-12-34-56-78', null, null],
            ['Unik', 'Omar', null, null, null, 's:0622222222', null, null],
            ['Vide', 'Nadia', null, null, null, null, null, null],          // sans numéro
            ['Insaisissable', 'Zohra', null, null, null, 's:06 12 AB', null, null], // lettres
        ]);

        $response = $this->get("/api/files/{$file->id}/duplicates", ['Authorization' => "Bearer {$this->token}"]);
        $response->assertOk();

        $stats = $response->json('stats');
        $this->assertSame(6, $stats['total_active']);
        $this->assertSame(1, $stats['duplicate_groups']);   // 0612345678 ×3
        $this->assertSame(3, $stats['contacts_in_groups']);
        $this->assertSame(2, $stats['without_usable_tel']); // vide + lettres
        $this->assertSame(1, $stats['not_in_any_group']);   // le numéro unique

        $group = $response->json('data.0');
        $this->assertSame('0612345678', $group['phone']);
        $this->assertCount(3, $group['contacts']);
    }

    public function test_different_numbers_are_not_grouped_and_detection_is_scoped_to_fiche(): void
    {
        $file1 = $this->importContacts([['A', 'Aline', null, null, null, 's:0612345678', null, null]]);
        $file2 = $this->importContacts([['B', 'Bernard', null, null, null, 's:0612345678', null, null]]);

        foreach ([$file1, $file2] as $file) {
            $response = $this->get("/api/files/{$file->id}/duplicates", ['Authorization' => "Bearer {$this->token}"]);
            $this->assertSame(0, $response->json('stats.duplicate_groups'),
                'Le scan doit rester local à la fiche.');
        }
    }

    public function test_extracted_contacts_are_excluded_then_restored_contacts_are_included_again(): void
    {
        $file = $this->importContacts([
            ['A', 'Aline', null, null, null, 's:0612345678', null, null],
            ['B', 'Bernard', null, null, null, 's:0612345678', null, null],
        ]);

        $ids = Contact::orderBy('id')->pluck('id')->all();
        $this->postJson("/api/files/{$file->id}/duplicates/extract", ['contact_ids' => [$ids[1]]],
            ['Authorization' => "Bearer {$this->token}"])->assertCreated();

        // Le contact extrait n'est plus dans le scan actif.
        $scan = json_decode($this->get("/api/files/{$file->id}/duplicates", ['Authorization' => "Bearer {$this->token}"])->getContent(), true);
        $this->assertSame(0, $scan['stats']['duplicate_groups']);
        $this->assertSame(1, $scan['stats']['total_active']);

        // Restauration : le contact revient dans le scan.
        $extraction = DuplicateExtraction::first();
        $this->postJson("/api/duplicate-extractions/{$extraction->id}/restore", [],
            ['Authorization' => "Bearer {$this->token}"])->assertOk();

        $scan2 = json_decode($this->get("/api/files/{$file->id}/duplicates", ['Authorization' => "Bearer {$this->token}"])->getContent(), true);
        $this->assertSame(1, $scan2['stats']['duplicate_groups']);
        $this->assertSame(2, $scan2['stats']['total_active']);
    }

    public function test_individual_selection_keep_one_and_extract_others(): void
    {
        $file = $this->importContacts([
            ['A', 'Aline', null, null, null, 's:0612345678', null, null],
            ['B', 'Bernard', null, null, null, 's:0612345678', null, null],
            ['C', 'Claire', null, null, null, 's:0612345678', null, null],
        ]);

        $ids = Contact::orderBy('id')->pluck('id');

        // Extraire B et C, garder A.
        $response = $this->postJson("/api/files/{$file->id}/duplicates/extract", ['contact_ids' => [$ids[1], $ids[2]]],
            ['Authorization' => "Bearer {$this->token}"])->assertCreated();

        $this->assertSame(2, $response->json('data.extraction.extracted_count'));
        $this->assertSame(1, $response->json('data.remaining_active_contacts'));

        $extraction = DuplicateExtraction::first();
        $this->assertSame([$ids[1], $ids[2]], $extraction->items()->pluck('contact_id')->sort()->values()->all());
        $this->assertSame($this->admin->id, $extraction->user_id);

        // B et C sont préservés et non modifiés (aucune suppression ni fusion),
        // A est le seul contact actif restant.
        $this->assertSame(['B', 'Bernard'], [Contact::find($ids[1])->nom, Contact::find($ids[1])->prenom]);
        $this->assertSame(['A'], $file->contacts()->active()->pluck('nom')->all());
    }

    public function test_invalid_extractions_and_foreign_fiche_are_rejected(): void
    {
        $file1 = $this->importContacts([['A', 'Aline', null, null, null, 's:0612345678', null, null], ['C', 'Claire', null, null, null, 's:0699999999', null, null]]);
        $file2 = $this->importContacts([['B', 'Bernard', null, null, null, 's:0612345678', null, null]]);

        $foreignId = Contact::where('imported_file_id', $file2->id)->first()->id;

        // Contact d'une autre fiche.
        $this->postJson("/api/files/{$file1->id}/duplicates/extract", ['contact_ids' => [$foreignId]],
            ['Authorization' => "Bearer {$this->token}"])->assertStatus(422);

        // Id fabricés.
        $this->postJson("/api/files/{$file1->id}/duplicates/extract", ['contact_ids' => [12345, 67890]],
            ['Authorization' => "Bearer {$this->token}"])->assertStatus(422);

        // Aucune sélection.
        $this->postJson("/api/files/{$file1->id}/duplicates/extract", ['contact_ids' => []],
            ['Authorization' => "Bearer {$this->token}"])->assertStatus(422);

        // Vérification : le refus global n'a touché personne (pas d'extraction partielle).
        $this->assertSame(0, DuplicateExtraction::count());
        $this->assertSame(0, \DB::table('duplicate_extraction_items')->count());
    }

    public function test_double_extraction_of_the_same_contact_is_refused(): void
    {
        $file = $this->importContacts([
            ['A', 'Aline', null, null, null, 's:0612345678', null, null],
            ['B', 'Bernard', null, null, null, 's:0612345678', null, null],
        ]);
        $id = Contact::first()->id;

        $this->postJson("/api/files/{$file->id}/duplicates/extract", ['contact_ids' => [$id]],
            ['Authorization' => "Bearer {$this->token}"])->assertCreated();

        $this->postJson("/api/files/{$file->id}/duplicates/extract", ['contact_ids' => [$id]],
            ['Authorization' => "Bearer {$this->token}"])->assertStatus(422);

        $this->assertSame(1, DuplicateExtraction::count());
    }

    public function test_cleaned_export_excludes_extracted_and_matches_active_counts(): void
    {
        $file = $this->importContacts([
            ['A', 'Aline', null, null, null, 's:0612345678', null, null],
            ['B', 'Bernard', null, null, null, 's:0612345678', null, null],
            ['C', 'Claire', null, null, null, 's:0688888888', null, null],
        ]);
        $ids = Contact::orderBy('id')->pluck('id');

        $this->postJson("/api/files/{$file->id}/duplicates/extract", ['contact_ids' => [$ids[1]]],
            ['Authorization' => "Bearer {$this->token}"])->assertCreated();

        $response = $this->get("/api/files/{$file->id}/cleaned-export", ['Authorization' => "Bearer {$this->token}"]);
        $response->assertOk()->assertHeader('Content-Type',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');

        $sheet = $this->readXlsx($response->streamedContent());
        $this->assertSame('NOM', $sheet[0][0]);
        $this->assertSame('Date de naissance', $sheet[0][7]);
        $names = array_column(array_slice($sheet, 1), 0);
        $this->assertSame(['A', 'C'], $names); // B extrait, absent

        // Export complet : tous les contacts de la fiche, extraits inclus.
        $all = $this->readXlsx($this->get("/api/files/{$file->id}/export", ['Authorization' => "Bearer {$this->token}"])->streamedContent());
        $this->assertSame(['A', 'B', 'C'], array_column(array_slice($all, 1), 0));
    }

    public function test_extraction_export_contains_only_extracted_contacts(): void
    {
        $file = $this->importContacts([
            ['A', 'Aline', null, null, null, 's:0612345678', 'a@x.ma', null],
            ['B', 'Bernard', null, null, null, 's:0612345678', 'b@x.ma', null],
            ['C', 'Claire', null, null, null, 's:0688888888', null, null],
        ]);
        $ids = Contact::orderBy('id')->pluck('id');

        $this->postJson("/api/files/{$file->id}/duplicates/extract", ['contact_ids' => [$ids[1]]],
            ['Authorization' => "Bearer {$this->token}"])->assertCreated();

        $extraction = DuplicateExtraction::first();
        $response = $this->get("/api/duplicate-extractions/{$extraction->id}/export", ['Authorization' => "Bearer {$this->token}"]);
        $response->assertOk();

        $sheet = $this->readXlsx($response->streamedContent());
        $b = array_slice($sheet, 1)[0];
        $this->assertSame(['B', 'Bernard', '0612345678', 'b@x.ma'], [$b[0], $b[1], $b[5], $b[6]]);
        $this->assertCount(2, $sheet); // en-tête + 1 contact
    }

    public function test_restoration_returns_contacts_without_creating_duplicates_or_erasing_history(): void
    {
        $file = $this->importContacts([
            ['A', 'Aline', null, null, null, 's:0612345678', null, null],
            ['B', 'Bernard', null, null, null, 's:0612345678', null, null],
        ]);
        $id = Contact::where('nom', 'B')->value('id');

        $this->postJson("/api/files/{$file->id}/duplicates/extract", ['contact_ids' => [$id]],
            ['Authorization' => "Bearer {$this->token}"])->assertCreated();

        $extraction = DuplicateExtraction::first();
        $response = $this->postJson("/api/duplicate-extractions/{$extraction->id}/restore", [],
            ['Authorization' => "Bearer {$this->token}"])->assertOk();

        $this->assertSame(1, $response->json('data.restored_count'));

        // Aucun contact recréé, aucune donnée modifiée.
        $this->assertSame(2, Contact::count());
        $this->assertSame('B', Contact::find($id)->nom);
        $this->assertSame('0612345678', Contact::find($id)->tel);

        // L'audit persiste : opération repositionnée + record de restauration.
        $this->assertSame(DuplicateExtraction::STATUS_RESTORED, $extraction->fresh()->status);
        $this->assertSame(1, ExtractionRestoration::count());

        // Une restauration répétée est refusée sans effet.
        $this->postJson("/api/duplicate-extractions/{$extraction->id}/restore", [],
            ['Authorization' => "Bearer {$this->token}"])->assertStatus(422);

        // Le contact redevient exportable dans la fiche nettoyée.
        $cleaned = $this->readXlsx($this->get("/api/files/{$file->id}/cleaned-export", ['Authorization' => "Bearer {$this->token}"])->streamedContent());
        $this->assertSame(3, count($cleaned)); // en-tête + 2 contacts actifs
    }

    public function test_extract_contact_of_archived_fiche_is_still_auditable(): void
    {
        $file = $this->importContacts([
            ['A', 'Aline', null, null, null, 's:0612345678', null, null],
            ['B', 'Bernard', null, null, null, 's:0612345678', null, null],
        ]);
        $ids = Contact::orderBy('id')->pluck('id');

        $this->postJson("/api/files/{$file->id}/duplicates/extract", ['contact_ids' => [$ids[1]]],
            ['Authorization' => "Bearer {$this->token}"])->assertCreated();

        // Archivage (soft delete) : history + extraction conservés.
        $this->deleteJson("/api/files/{$file->id}", [], ['Authorization' => "Bearer {$this->token}"])->assertOk();

        $this->assertDatabaseHas('imported_files', ['id' => $file->id]); // ligne douce (deleted_at)
        $extraction = DuplicateExtraction::first();
        $this->get("/api/duplicate-extractions/{$extraction->id}/export", ['Authorization' => "Bearer {$this->token}"])
            ->assertOk();
    }

    private function readXlsx(string $bytes): array
    {
        $path = tempnam(sys_get_temp_dir(), 'read_xlsx_');
        file_put_contents($path, $bytes);
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $data = $spreadsheet->getActiveSheet()->toArray();
        $spreadsheet->disconnectWorksheets();
        unlink($path);

        return $data;
    }
}
