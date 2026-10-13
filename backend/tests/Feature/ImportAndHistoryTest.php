<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\ImportedFile;
use App\Models\ImportHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Tests\CreatesXlsx;

class ImportAndHistoryTest extends TestCase
{
    use RefreshDatabase, CreatesXlsx;

    private function actingAdmin(): array
    {
        $admin = User::create([
            'name' => 'Admin Import',
            'email' => 'admin@test.ma',
            'password' => 'motdepasse1',
            'role' => 'admin',
            'active' => true,
        ]);

        return [$admin, $admin->createToken('session')->plainTextToken];
    }

    public function test_valid_excel_file_is_imported_with_leading_zeros_and_strings(): void
    {
        [$admin, $token] = $this->actingAdmin();

        $rows = [
            $this->headersRow(),
            ['EL AMRANI', 'MOHAMMED', '12 rue des Lilas', 's:06801', 'CASABLANCA', 's:0612345678', 'mo@ex.ma', '12/05/1978'],
            ['BENALI', 'AHMED', '5 av. Hassan II', 's:20000', 'RABAT', 's:0612345678', null, null],
            ['Tazi', 'Sara', null, null, null, 's:06 99 88 77 66', 'sara@example.com', null],
        ];

        $response = $this->postUploaded($token, $this->writeXlsx($rows));

        $response->assertCreated();
        $history = ImportHistory::latest('id')->first();
        $this->assertNotNull($history->imported_file_id);
        $this->assertSame(ImportHistory::STATUS_COMPLETED, $history->status);
        $this->assertSame(3, $history->total_rows);
        $this->assertSame(3, $history->successful_rows);
        $this->assertSame(0, $history->failed_rows);

        $first = Contact::first();
        $this->assertSame('06801', $first->cp); // zéro initial préservé
        $this->assertSame('0612345678', $first->tel); // chaîne, pas entier
        $this->assertSame('1978-05-12', $first->date_naissance->toDateString());
        $this->assertSame('06 99 88 77 66', Contact::where('prenom', 'Sara')->value('tel')); // formatage original conservé

        // Normalisation des numéros identiques au formatage prés traitant.
        $this->assertSame(Contact::find(1)->telephone_normalise, Contact::find(2)->telephone_normalise);

        $file = ImportedFile::first();
        $this->assertSame(ImportedFile::STATUS_IMPORTED, $file->status);
        $this->assertSame($admin->id, $file->imported_by);
        $this->assertFileExists(\Storage::disk('local')->path($file->storage_path)); // original conservé
    }

    public function test_header_variants_are_matched_without_interpreting_unknown_columns(): void
    {
        [, $token] = $this->actingAdmin();

        $rows = [
            [' Nom ', 'Prenom', 'DATE DE NAISSANCE', 'Colonne inconnue'],
            ['DUPONT', 'Léa', '01/02/1990', 'ne pas toucher'],
        ];

        $response = $this->postUploaded($token, $this->writeXlsx($rows, 'variants.xlsx'));
        $response->assertCreated();

        $contact = Contact::first();
        $this->assertSame('DUPONT', $contact->nom);
        $this->assertSame('1990-02-01', $contact->date_naissance->toDateString());
        $this->assertNull($contact->tel);
        $this->assertNull($contact->ville);
    }

    public function test_missing_headers_are_reported_and_nothing_is_imported(): void
    {
        [, $token] = $this->actingAdmin();

        $rows = [
            ['NOM', 'ADRESSE'],
            ['DUPONT', '12 rue A'],
        ];

        $response = $this->postUploaded($token, $this->writeXlsx($rows, 'sans-headers.xlsx'));

        $response->assertStatus(422)
            ->assertJsonStructure(['message', 'missing_headers', 'history_id']);

        $this->assertSame('PRENOM', $response->json('missing_headers.0'));
        $this->assertSame(0, Contact::count());
        $this->assertSame(0, ImportedFile::count());

        $history = ImportHistory::latest('id')->first();
        $this->assertNotNull($history); // tentative enregistrée même en cas d'échec
        $this->assertSame(ImportHistory::STATUS_FAILED, $history->status);
        $this->assertStringContainsString('PRENOM', $history->error_summary);
    }

    public function test_invalid_rows_are_reported_and_valid_rows_imported(): void
    {
        [, $token] = $this->actingAdmin();

        $rows = [
            $this->headersRow(),
            ['DUPONT', 'Jean', null, null, null, null, 'email-invalide', '31/02/1990'], // email + date invalides
            ['MARTIN', 'Claire', null, null, null, null, 'claire@example.com', null],
            [null, null, null, null, null, null, null, null], // ligne vide ignorée (skipped)
        ];

        $response = $this->postUploaded($token, $this->writeXlsx($rows, 'mixte.xlsx'));
        $response->assertCreated();

        $history = ImportHistory::latest('id')->first();
        $this->assertSame(ImportHistory::STATUS_PARTIAL, $history->status);
        $this->assertSame(3, $history->total_rows);
        $this->assertSame(1, $history->successful_rows);
        $this->assertSame(1, $history->failed_rows);
        $this->assertSame(1, $history->skipped_rows);

        $rowErrors = $history->row_errors;
        $this->assertNotEmpty($rowErrors);
        $this->assertSame(2, $rowErrors[0]['row']); // ligne Excel
        $this->assertStringContainsString('EMAIL', $rowErrors[0]['field']);

        $this->assertSame(1, Contact::count());
        $this->assertSame('MARTIN', Contact::first()->nom);
    }

    public function test_identical_file_requires_explicit_confirmation(): void
    {
        [, $token] = $this->actingAdmin();
        $rows = [$this->headersRow(), ['A', 'B', null, null, null, null, null, null]];
        $path = $this->writeXlsx($rows);

        $this->postUploaded($token, $this->uploadedFrom($path, 'fiche-contact.xlsx'))->assertCreated();

        // Second import du même contenu : refusé avec décision explicite demandée.
        $dupResponse = $this->postUploaded($token, $this->uploadedFrom($path, 'fiche-contact-copie.xlsx'));
        $dupResponse->assertStatus(409)->assertJsonStructure(['message', 'duplicate_of', 'history_id']);

        $failedHistory = ImportHistory::whereNull('imported_file_id')->latest('id')->first();
        $this->assertNotNull($failedHistory);

        // Confirmation explicite : import autorisé (nouvelle fiche, aucune fusion).
        $this->post('/api/files/import', [
            'file' => $this->uploadedFrom($path, 'fiche-contact-copie.xlsx'),
            'confirm_duplicate' => true,
        ], $this->multipartHeaders($token))->assertCreated();

        $this->assertSame(2, ImportedFile::count());
        $this->assertSame(2, Contact::count()); // 1 ligne de données × 2 imports, aucune fusion
    }

    public function test_new_import_never_overwrites_an_existing_one(): void
    {
        [, $token] = $this->actingAdmin();

        $rows1 = [$this->headersRow(), ['A', 'Aline', null, null, null, null, null, null]];
        $rows2 = [$this->headersRow(), ['B', 'Bernard', null, null, null, null, null, null]];

        $this->postUploaded($token, $this->writeXlsx($rows1, 'un.xlsx'))->assertCreated();
        $this->postUploaded($token, $this->writeXlsx($rows2, 'deux.xlsx'))->assertCreated();

        $this->assertSame(2, ImportedFile::count());
        $this->assertSame(2, Contact::count());
    }

    public function test_oversized_or_badly_typed_uploads_are_rejected(): void
    {
        [, $token] = $this->actingAdmin();

        $this->post('/api/files/import', [
            'file' => $this->uploadedFrom(
                tempnam(sys_get_temp_dir(), 'pdf_'),
                'fake.pdf',
                'application/pdf'
            ),
        ], $this->multipartHeaders($token))->assertStatus(422);

        $this->assertSame(0, Contact::count());
    }

    public function test_corrupt_xlsx_content_fails_safely_with_history(): void
    {
        [, $token] = $this->actingAdmin();

        $fake = tempnam(sys_get_temp_dir(), 'bad_');
        file_put_contents($fake, 'ceci n\'est pas un zip/xlsx');

        $response = $this->post('/api/files/import', [
            'file' => $this->uploadedFrom($fake, 'corrompu.xlsx'),
        ], $this->multipartHeaders($token));

        $response->assertStatus(422);
        $this->assertSame(0, Contact::count());
        $this->assertNotNull(ImportHistory::latest('id')->first());
    }

    /** Headers des posts multipart : Accept JSON pour des erreurs 422 lisibles. */
    private function multipartHeaders(string $token): array
    {
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }

    private function postUploaded(string $token, string $path, string $name = 'fiche-contact.xlsx')
    {
        return $this->post('/api/files/import', [
            'file' => $this->uploadedFrom($path, $name),
        ], $this->multipartHeaders($token));
    }
}
