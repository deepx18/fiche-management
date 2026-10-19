<?php

namespace Tests\Unit;

use App\Services\Google\GoogleSheetsClient;
use App\Services\Google\GoogleSheetsException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le client Google Sheets est testé avec `Http::fake` : aucune requête réelle
 * vers Google, aucun identifiant réel. Une clé RSA jetable est générée pour
 * valider la signature du JWT de compte de service.
 */
class GoogleSheetsClientTest extends TestCase
{
    private const SPREADSHEET = 'classeur-de-test';

    private ?string $credentialsPath = null;

    protected function tearDown(): void
    {
        if ($this->credentialsPath !== null && is_file($this->credentialsPath)) {
            @unlink($this->credentialsPath);
        }
        $this->credentialsPath = null;

        parent::tearDown();
    }

    /** @param array<string, mixed> $overrides */
    private function configure(array $overrides = []): void
    {
        config(array_merge([
            'production.spreadsheet_id' => self::SPREADSHEET,
            'production.range' => 'Production!A1:E100',
            'production.credentials_path' => $this->writeCredentials(),
            'production.timeout' => 5,
        ], $overrides));
    }

    private function writeCredentials(string $json = ''): string
    {
        if ($json === '') {
            $privateKey = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
            openssl_pkey_export($privateKey, $pem);
            $json = json_encode([
                'client_email' => 'compte-service@example.iam.gserviceaccount.com',
                'private_key' => $pem,
            ]);
        }

        $path = tempnam(sys_get_temp_dir(), 'gsa_');
        file_put_contents($path, $json);
        $this->credentialsPath = $path;

        return $path;
    }

    private function fakeToken(string $token = 'jeton-test'): void
    {
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => $token, 'expires_in' => 3600]),
        ]);
    }

    public function test_missing_configuration_is_reported_without_any_request(): void
    {
        Http::fake();
        config(['production.spreadsheet_id' => null, 'production.credentials_path' => null]);

        try {
            (new GoogleSheetsClient)->rows();
            $this->fail('Une exception était attendue.');
        } catch (GoogleSheetsException $e) {
            $this->assertStringContainsString('GOOGLE_SHEETS_SPREADSHEET_ID', $e->getMessage());
            $this->assertStringContainsString('GOOGLE_APPLICATION_CREDENTIALS', $e->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_missing_spreadsheet_id_is_reported(): void
    {
        Http::fake();
        $this->configure(['production.spreadsheet_id' => null]);

        $this->expectException(GoogleSheetsException::class);
        $this->expectExceptionMessageMatches('/GOOGLE_SHEETS_SPREADSHEET_ID/');

        (new GoogleSheetsClient)->rows();
    }

    public function test_missing_or_unreadable_credentials_file_is_reported(): void
    {
        Http::fake();
        $this->configure(['production.credentials_path' => 'storage/app/google/absent.json']);

        $this->expectException(GoogleSheetsException::class);
        $this->expectExceptionMessageMatches('/introuvable|illisible/');

        (new GoogleSheetsClient)->rows();
    }

    public function test_malformed_credentials_json_is_reported(): void
    {
        Http::fake();
        $this->configure(['production.credentials_path' => $this->writeCredentials('{"pas_un":"compte de service"}')]);

        $this->expectException(GoogleSheetsException::class);
        $this->expectExceptionMessageMatches('/identifiants Google sont invalides/');

        (new GoogleSheetsClient)->rows();
    }

    public function test_refused_token_exchange_is_reported_as_invalid_credentials(): void
    {
        Http::fake(['oauth2.googleapis.com/*' => Http::response(['error' => 'invalid_grant'], 400)]);
        $this->configure();

        $this->expectException(GoogleSheetsException::class);
        $this->expectExceptionMessageMatches('/identifiants Google sont invalides/');

        (new GoogleSheetsClient)->rows();
    }

    public function test_rows_are_parsed_with_leading_zeros_kept_and_blank_cells_nulled(): void
    {
        $this->configure();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'jeton', 'expires_in' => 3600]),
            'sheets.googleapis.com/*' => Http::response([
                'range' => 'Production!A1:E2',
                'majorDimension' => 'ROWS',
                'values' => [
                    ['N° contrat', 'Client', 'Ville', 'Produit', 'Statut'],
                    ['CT-001', '  Amrani Mohamed  ', '', null, 'Actif'],
                ],
            ]),
        ]);

        $rows = (new GoogleSheetsClient)->rows();

        $this->assertCount(2, $rows);
        $this->assertSame('CT-001', $rows[1][0]); // zéro initial conservé (chaîne)
        $this->assertSame('Amrani Mohamed', $rows[1][1]); // espaces superflus retirés
        $this->assertNull($rows[1][2]); // cellule vide → null
        $this->assertNull($rows[1][3]);
        $this->assertSame('Actif', $rows[1][4]);

        // Le JWT demandé porte la portée lecture seule, et la plage configurée
        // est bien transmise à l'API.
        Http::assertSent(function ($request) {
            if (! str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return false;
            }
            $assertion = (string) ($request->data()['assertion'] ?? '');
            $segments = explode('.', $assertion);
            if (count($segments) !== 3) {
                return false;
            }
            $claims = json_decode((string) base64_decode(strtr($segments[1], '-_', '+/')), true);

            return str_contains((string) ($claims['scope'] ?? ''), 'https://www.googleapis.com/auth/spreadsheets.readonly')
                && ($claims['iss'] ?? null) === 'compte-service@example.iam.gserviceaccount.com';
        });

        $expectedPath = '/v4/spreadsheets/'.self::SPREADSHEET.'/values/'.rawurlencode('Production!A1:E100');
        Http::assertSent(fn ($request) => str_contains($request->url(), $expectedPath)
            && str_contains($request->url(), 'majorDimension=ROWS'));
    }

    public function test_an_empty_range_returns_no_rows(): void
    {
        $this->configure();
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'jeton', 'expires_in' => 3600]),
            'sheets.googleapis.com/*' => Http::response(['range' => 'Production!A1:E100', 'majorDimension' => 'ROWS']),
        ]);

        $this->assertSame([], (new GoogleSheetsClient)->rows());
    }

    public function test_unauthorized_forbidden_and_not_found_are_mapped_to_actionable_messages(): void
    {
        $this->configure();

        // Le statut renvoyé par l'API Sheets est piloté par une variable : un
        // seul faux serveur, quatre cas testés sans dépendre d'une remise à
        // zéro des stubs entre les itérations.
        $status = 401;
        Http::fake(function ($request) use (&$status) {
            if (str_contains($request->url(), 'oauth2.googleapis.com')) {
                return Http::response(['access_token' => 'jeton', 'expires_in' => 3600]);
            }

            return Http::response(['error' => 'boom'], $status);
        });

        $cases = [
            401 => '/Authentification Google refusée/',
            403 => '/Partagez la feuille avec l\'adresse/',
            404 => '/Classeur Google Sheets introuvable/',
            500 => '/Erreur de l\'API Google Sheets \(HTTP 500\)/',
        ];

        foreach ($cases as $code => $expected) {
            $status = $code;

            try {
                (new GoogleSheetsClient)->rows();
                $this->fail("Une exception était attendue pour le statut {$code}.");
            } catch (GoogleSheetsException $e) {
                $this->assertMatchesRegularExpression($expected, $e->getMessage(), "Statut {$code}");
                // Aucun secret ni chemin de fichier n'est divulgué.
                $this->assertStringNotContainsString((string) $this->credentialsPath, $e->getMessage());
            }
        }
    }
}
