<?php

namespace Tests\Unit;

use App\Services\Production\ProductionSheetSchema;
use App\Services\Production\ProductionValueException;
use Tests\TestCase;

/**
 * Fixtures synthétiques. Aucun accès Google ni base de données.
 */
class ProductionSheetSchemaTest extends TestCase
{
    private function headers(): array
    {
        return [
            'DATE', 'CIAL', 'NOM', 'PRENOM', 'COTISATION', 'PRIME ANNUELLE',
            'CIE', 'PRODUIT', "Date d'effet", 'VALIDATION', 'TYPE DE SIGNATURE',
        ];
    }

    public function test_every_required_header_maps_to_the_expected_canonical_field(): void
    {
        $schema = new ProductionSheetSchema;
        $resolution = $schema->resolveHeaderRow($this->headers());

        $this->assertSame([], $resolution['missing']);
        $this->assertSame([], $resolution['duplicates']);
        $this->assertSame([], $resolution['unrecognized']);

        $expected = [
            'date' => 0,
            'cial' => 1,
            'nom' => 2,
            'prenom' => 3,
            'cotisation' => 4,
            'prime_annuelle' => 5,
            'cie' => 6,
            'produit' => 7,
            'date_effet' => 8,
            'validation' => 9,
            'type_signature' => 10,
        ];
        $this->assertSame($expected, $resolution['map']);
    }

    public function test_reordered_headers_map_by_name_not_position(): void
    {
        $schema = new ProductionSheetSchema;
        $headers = ['NOM', 'DATE', 'PRODUIT', 'CIAL', 'PRENOM', 'CIE', 'COTISATION', 'PRIME ANNUELLE', "Date d'effet", 'TYPE DE SIGNATURE', 'VALIDATION'];

        $map = $schema->resolveHeaderRow($headers)['map'];

        $this->assertSame(0, $map['nom']);
        $this->assertSame(1, $map['date']);
        $this->assertSame(2, $map['produit']);
        $this->assertSame(3, $map['cial']);
        $this->assertSame(7, $map['prime_annuelle']);
        $this->assertSame(8, $map['date_effet']);
        $this->assertSame(10, $map['validation']);
    }

    public function test_headers_tolerate_case_whitespace_non_breaking_spaces_and_typographic_apostrophes(): void
    {
        $schema = new ProductionSheetSchema;
        $headers = [
            ' date ', 'cial', ' Nom ', 'prénom', 'cotisation',
            "PRIME\u{00A0}ANNUELLE", ' CIE', 'produit', "Date d\u{2019}effet",
            'VALIDATION', "TYPE  DE\tSIGNATURE",
        ];

        $resolution = $schema->resolveHeaderRow($headers);

        $this->assertSame([], $resolution['missing']);
        $this->assertSame([], $resolution['duplicates']);
        $this->assertSame([], $resolution['unrecognized']);
        $this->assertSame(8, $resolution['map']['date_effet']);
        $this->assertSame(5, $resolution['map']['prime_annuelle']);
        $this->assertSame(10, $resolution['map']['type_signature']);
    }

    public function test_missing_and_duplicated_headers_are_reported(): void
    {
        $schema = new ProductionSheetSchema;
        $headers = $this->headers();
        array_splice($headers, 2, 1); // retire NOM
        $headers[] = 'DATE'; // duplique DATE

        $resolution = $schema->resolveHeaderRow($headers);

        $this->assertContains('nom', $resolution['missing']);
        $this->assertSame(['DATE'], $resolution['duplicates']);
    }

    public function test_trailing_empty_columns_are_ignored_and_unknown_headers_reported(): void
    {
        $schema = new ProductionSheetSchema;
        $headers = [...$this->headers(), '', '   ', 'REMARQUE'];

        $resolution = $schema->resolveHeaderRow($headers);

        $this->assertSame([], $resolution['missing']);
        $this->assertSame(['REMARQUE'], $resolution['unrecognized']);
    }

    public function test_day_first_dates_and_iso_dates_are_parsed(): void
    {
        $schema = new ProductionSheetSchema;

        $this->assertSame('2026-10-01', $schema->parseDate('01/10/2026'));
        $this->assertSame('2026-12-01', $schema->parseDate('1/12/2026'));
        $this->assertSame('2026-02-28', $schema->parseDate('2026-02-28'));
        // Série Google Sheets (cellule non formatée en date).
        $this->assertSame('2026-10-01', $schema->parseDate('46296'));
    }

    public function test_invalid_dates_are_rejected(): void
    {
        $schema = new ProductionSheetSchema;

        foreach (['31/02/2026', '2026-13-01', '01-10-2026', 'jamais', '99/99/9999', '2026'] as $value) {
            try {
                $schema->parseDate($value);
                $this->fail("Date acceptée à tort : {$value}");
            } catch (ProductionValueException $e) {
                $this->assertStringContainsString('Date invalide', $e->getMessage());
            }
        }

        $this->expectException(ProductionValueException::class);
        $schema->parseDate(null);
    }

    public function test_amounts_are_parsed_without_rounding_or_zero_conversion(): void
    {
        $schema = new ProductionSheetSchema;

        $this->assertSame('91.55', $schema->parseAmount('91.55'));
        $this->assertSame('1098.6', $schema->parseAmount('1098.6'));
        $this->assertSame('1098.60', $schema->parseAmount('1 098,60'));
        $this->assertSame('1098.60', $schema->parseAmount("1\u{00A0}098,60"));
        $this->assertSame('-12.5', $schema->parseAmount('-12,5'));
        $this->assertNull($schema->parseAmount(null));
        $this->assertNull($schema->parseAmount('   '));
    }

    public function test_invalid_or_over_precise_amounts_are_rejected_instead_of_rounded(): void
    {
        $schema = new ProductionSheetSchema;

        foreach (['N/A', '1.23456', '€ 91,55', '91,55 €', '1,098.60', 'abc', '12 34'] as $value) {
            try {
                $schema->parseAmount($value);
                $this->fail("Montant accepté à tort : {$value}");
            } catch (ProductionValueException $e) {
                $this->assertStringContainsString('Montant invalide', $e->getMessage());
            }
        }
    }

    public function test_identity_normalization_is_case_and_accent_insensitive_but_keeps_leading_zeroes(): void
    {
        $schema = new ProductionSheetSchema;

        $this->assertSame('0123456', $schema->identityValue('cial', '0123456'));
        $this->assertSame('fes', $schema->identityValue('cie', 'Fès'));
        $this->assertSame('cover up - n3', $schema->identityValue('produit', 'COVER  UP - N3'));
        $this->assertSame('2026-10-01', $schema->identityValue('date', '2026-10-01'));
    }
}
