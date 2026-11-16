<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Telephony\CallSearchQuery;
use App\Services\Telephony\Issabel\IssabelCdrRepository;
use App\Services\Telephony\TelephonyException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Lecture du CDR Issabel sur une connexion sqlite en mémoire qui remplace la
 * base MySQL dédiée : la requête SQL réellement exécutée est la même, sans
 * PBX ni identifiants réels.
 */
class IssabelCdrRepositoryTest extends TestCase
{
    use RefreshDatabase;

    /** @param list<string> $columns */
    private function useCdrDatabase(array $columns = [
        'calldate', 'uniqueid', 'linkedid', 'src', 'dst',
        'duration', 'billsec', 'disposition', 'recordingfile',
    ]): void
    {
        config()->set('telephony.issabel.enabled', true);
        config()->set('telephony.issabel.cdr_connection', 'issabel');
        config()->set('telephony.issabel.cdr_table', 'cdr');
        config()->set('telephony.issabel.agent_column', '');
        config()->set('telephony.issabel.direction_column', '');
        config()->set('database.connections.issabel', [
            'driver' => 'sqlite',
            'host' => '127.0.0.1',
            'database' => ':memory:',
            'username' => 'lecture',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('issabel');
        Schema::connection('issabel')->dropIfExists('cdr');

        Schema::connection('issabel')->create('cdr', function (Blueprint $table) use ($columns): void {
            $table->id();
            foreach ($columns as $column) {
                $table->string($column)->nullable();
            }
        });
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'calldate' => '2026-10-19 10:00:00',
            'uniqueid' => 'cdr-'.bin2hex(random_bytes(4)),
            'linkedid' => 'link-1',
            'src' => '0612345678',
            'dst' => '0140000000',
            'duration' => 120,
            'billsec' => 100,
            'disposition' => 'ANSWERED',
            'recordingfile' => '2026/10/19/rec.wav',
        ], $overrides);
    }

    private function query(string $e164 = '+33612345678'): CallSearchQuery
    {
        return new CallSearchQuery(
            e164: $e164,
            from: CarbonImmutable::parse('2026-10-01')->startOfDay(),
            to: CarbonImmutable::parse('2026-10-31')->endOfDay(),
            limit: 50,
        );
    }

    public function test_formatted_national_and_international_numbers_are_matched(): void
    {
        $this->useCdrDatabase();

        $rows = [
            $this->row(['uniqueid' => 'spaces', 'src' => '06 12 34 56 78']),
            $this->row(['uniqueid' => 'international', 'dst' => '+33 6 12 34 56 78']),
            $this->row(['uniqueid' => 'zero-zero', 'src' => '0033612345678']),
            $this->row(['uniqueid' => 'dots', 'src' => '(06) 12.34.56.78']),
            $this->row(['uniqueid' => 'other', 'src' => '+33700000000']),
            $this->row(['uniqueid' => 'out-of-range', 'src' => '0612345678', 'calldate' => '2026-01-01 09:00:00']),
        ];

        DB::connection('issabel')->table('cdr')->insert($rows);

        $matches = app(IssabelCdrRepository::class)->search($this->query());

        $this->assertEqualsCanonicalizing(
            ['spaces', 'international', 'zero-zero', 'dots'],
            array_column($matches, 'uniqueid'),
        );
    }

    public function test_provider_values_that_are_not_valid_e164_are_matched_by_inclusion(): void
    {
        $this->useCdrDatabase();

        DB::connection('issabel')->table('cdr')->insert([
            $this->row(['uniqueid' => 'country-code', 'src' => '33615574414']),
            $this->row(['uniqueid' => 'double-zero', 'src' => '00615574414']),
            $this->row(['uniqueid' => 'national-without-zero', 'dst' => '615574414']),
            $this->row(['uniqueid' => 'other-number', 'src' => '33615574415']),
        ]);

        $matches = app(IssabelCdrRepository::class)->search($this->query('+33615574414'));

        $this->assertEqualsCanonicalizing(
            ['country-code', 'double-zero', 'national-without-zero'],
            array_column($matches, 'uniqueid'),
        );
    }

    public function test_a_number_whose_digits_are_absent_is_not_returned(): void
    {
        $this->useCdrDatabase();
        DB::connection('issabel')->table('cdr')->insert($this->row(['src' => '+21270000000']));

        $this->assertSame([], app(IssabelCdrRepository::class)->search($this->query()));
    }

    public function test_optional_columns_absent_do_not_break_the_query(): void
    {
        $this->useCdrDatabase(['calldate', 'src', 'dst']);

        DB::connection('issabel')->table('cdr')->insert([
            'calldate' => '2026-10-19 10:00:00',
            'src' => '06 12 34 56 78',
            'dst' => '0140000000',
        ]);

        $matches = app(IssabelCdrRepository::class)->search($this->query());

        $this->assertCount(1, $matches);
        $this->assertArrayNotHasKey('uniqueid', $matches[0]);
    }

    public function test_missing_required_column_is_a_configuration_error(): void
    {
        $this->useCdrDatabase(['calldate', 'src']);

        $this->expectException(TelephonyException::class);
        $this->expectExceptionMessage('dst');

        app(IssabelCdrRepository::class)->search($this->query());
    }

    public function test_disabled_issabel_is_a_configuration_error_without_query(): void
    {
        $this->useCdrDatabase();
        config()->set('telephony.issabel.enabled', false);

        $this->expectException(TelephonyException::class);
        $this->expectExceptionMessage('ISSABEL_ENABLED');

        app(IssabelCdrRepository::class)->search($this->query());
    }

    public function test_custom_connection_name_is_used_for_credentials(): void
    {
        $this->useCdrDatabase();
        config()->set('telephony.issabel.cdr_connection', 'issabel-custom');
        config()->set('database.connections.issabel-custom', [
            'driver' => 'sqlite',
            'host' => '127.0.0.1',
            'database' => ':memory:',
            'username' => 'lecture',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::purge('issabel-custom');

        Schema::connection('issabel-custom')->create('cdr', function (Blueprint $table): void {
            $table->id();
            foreach (['calldate', 'uniqueid', 'src', 'dst'] as $column) {
                $table->string($column)->nullable();
            }
        });
        DB::connection('issabel-custom')->table('cdr')->insert([
            'calldate' => '2026-10-19 10:00:00',
            'uniqueid' => 'custom',
            'src' => '06 12 34 56 78',
            'dst' => '0140000000',
        ]);

        $matches = app(IssabelCdrRepository::class)->search($this->query());

        $this->assertCount(1, $matches);
        $this->assertSame('custom', $matches[0]['uniqueid']);
    }
}
