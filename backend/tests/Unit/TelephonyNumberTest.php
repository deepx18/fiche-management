<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Telephony\TelephonyNumber;
use Tests\TestCase;

class TelephonyNumberTest extends TestCase
{
    public function test_national_and_international_french_forms_normalize_to_the_same_e164(): void
    {
        $expected = '+33612345678';

        foreach ([
            '0612345678',
            '06 12 34 56 78',
            '+33 6 12 34 56 78',
            '+33612345678',
            '0033612345678',
            '(06) 12.34.56.78',
        ] as $raw) {
            $this->assertSame($expected, TelephonyNumber::french($raw), $raw);
        }
    }

    public function test_explicit_foreign_country_code_is_preserved_and_not_rewritten(): void
    {
        $this->assertNull(TelephonyNumber::french('+212612345678'));
        $this->assertSame('+212612345678', TelephonyNumber::e164('+212612345678'));

        $result = TelephonyNumber::frenchWithReason('+212612345678');
        $this->assertNull($result['e164']);
        $this->assertStringContainsString('étranger', (string) $result['reason']);
    }

    public function test_matching_uses_normalized_values_not_raw_strings(): void
    {
        $this->assertTrue(TelephonyNumber::matches('0612345678', '+33612345678'));
        $this->assertTrue(TelephonyNumber::matches('+33 6 12 34 56 78', '+33612345678'));
        $this->assertFalse(TelephonyNumber::matches('+212612345678', '+33612345678'));
        $this->assertFalse(TelephonyNumber::matches(null, '+33612345678'));
    }

    public function test_candidates_cover_provider_formats(): void
    {
        $this->assertContains('+33612345678', TelephonyNumber::candidates('+33612345678'));
        $this->assertContains('33612345678', TelephonyNumber::candidates('+33612345678'));
        $this->assertContains('0612345678', TelephonyNumber::candidates('+33612345678'));
        $this->assertContains('612345678', TelephonyNumber::candidates('+33612345678'));
    }
}
