<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\CallRecord;
use App\Services\Telephony\CallSearchQuery;
use App\Services\Telephony\Issabel\IssabelCallProvider;
use App\Services\Telephony\Issabel\IssabelCdrReader;
use App\Services\Telephony\TelephonyException;
use Carbon\CarbonImmutable;
use Tests\TestCase;

class IssabelCallProviderTest extends TestCase
{
    private function query(): CallSearchQuery
    {
        return new CallSearchQuery(
            e164: '+33612345678',
            from: CarbonImmutable::parse('2026-10-01')->startOfDay(),
            to: CarbonImmutable::parse('2026-10-19')->endOfDay(),
            limit: 50,
        );
    }

    private function provider(array $rows): IssabelCallProvider
    {
        config([
            'telephony.issabel.enabled' => true,
            'telephony.issabel.agent_column' => 'agent',
            'telephony.issabel.direction_column' => 'direction',
            'telephony.issabel.recording_root' => '/tmp/issabel-recordings',
        ]);

        return new IssabelCallProvider(new class($rows) implements IssabelCdrReader
        {
            public function __construct(private readonly array $rows) {}

            public function search(CallSearchQuery $query): array
            {
                return $this->rows;
            }
        });
    }

    public function test_cdr_row_is_mapped_to_normalized_call_metadata(): void
    {
        $provider = $this->provider([[
            'uniqueid' => 'cdr-123',
            'linkedid' => 'link-1',
            'src' => '06 12 34 56 78',
            'dst' => '0140000000',
            'calldate' => '2026-10-19 10:00:00',
            'duration' => 120,
            'billsec' => 100,
            'disposition' => 'ANSWERED',
            'recordingfile' => '2026/10/rec-123.wav',
            'agent' => '901',
            'direction' => 'inbound',
        ]]);

        $calls = $provider->search($this->query());
        $this->assertCount(1, $calls);

        $call = $calls[0];
        $this->assertSame('cdr-123', $call->providerCallId);
        $this->assertSame('06 12 34 56 78', $call->rawPhone);
        $this->assertSame('+33612345678', $call->matchedPhoneE164);
        $this->assertSame('ANSWERED', $call->status);
        $this->assertSame('inbound', $call->direction);
        $this->assertSame(120, $call->durationSeconds);
        $this->assertSame(100, $call->billableSeconds);
        $this->assertSame('901', $call->agent);
        $this->assertSame(CallRecord::RECORDING_REFERENCE_ONLY, $call->recordingState);
        $this->assertSame('2026/10/rec-123.wav', $call->recordingProviderReference);
        $this->assertSame('link-1', $call->metadata['linkedid'] ?? null);
    }

    public function test_raw_provider_forms_are_matched_without_normalization(): void
    {
        $provider = $this->provider([
            [
                'uniqueid' => 'cdr-country-code',
                'src' => '33612345678',
                'dst' => '0140000000',
                'calldate' => '2026-10-19 10:00:00',
            ],
            [
                'uniqueid' => 'cdr-double-zero',
                'src' => '00612345678',
                'dst' => '0140000000',
                'calldate' => '2026-10-19 10:00:00',
            ],
        ]);

        $calls = $provider->search($this->query());

        $this->assertCount(2, $calls);
        $this->assertEqualsCanonicalizing(
            ['cdr-country-code', 'cdr-double-zero'],
            array_map(static fn ($call): string => $call->providerCallId, $calls),
        );
        $this->assertSame('33612345678', $calls[0]->rawPhone);
    }

    public function test_rows_that_do_not_actually_match_the_target_are_discarded(): void
    {
        $provider = $this->provider([[
            'uniqueid' => 'cdr-other',
            'src' => '+33140000000',
            'dst' => '0140000000',
            'calldate' => '2026-10-19 10:00:00',
        ]]);

        $this->assertSame([], $provider->search($this->query()));
    }

    public function test_missing_uniqueid_uses_a_stable_documented_fingerprint(): void
    {
        $row = [
            'src' => '0612345678',
            'dst' => '0140000000',
            'calldate' => '2026-10-19 10:00:00',
            'duration' => 120,
            'billsec' => 100,
        ];

        $first = $this->provider([$row])->search($this->query())[0];
        $second = $this->provider([$row])->search($this->query())[0];
        $other = $this->provider([array_merge($row, ['duration' => 121])])->search($this->query())[0];

        $this->assertStringStartsWith('issabel-fp-', $first->providerCallId);
        $this->assertSame($first->providerCallId, $second->providerCallId);
        $this->assertNotSame($first->providerCallId, $other->providerCallId);
    }

    public function test_absolute_asterisk_recording_path_is_reduced_to_a_safe_relative_reference(): void
    {
        $provider = $this->provider([[
            'uniqueid' => 'cdr-abs',
            'src' => '0612345678',
            'dst' => '0140000000',
            'calldate' => '2026-10-19 10:00:00',
            'recordingfile' => '/var/spool/asterisk/monitor/2026/10/20/out-123.wav',
        ]]);

        $call = $provider->search($this->query())[0];

        $this->assertSame('2026/10/20/out-123.wav', $call->recordingProviderReference);
        $this->assertSame(CallRecord::RECORDING_REFERENCE_ONLY, $call->recordingState);
    }

    public function test_url_or_traversal_recording_reference_is_refused(): void
    {
        foreach (['https://example.test/rec.wav', '../../etc/passwd', 'C:\\recordings\\rec.wav'] as $reference) {
            $provider = $this->provider([[
                'uniqueid' => 'cdr-unsafe',
                'src' => '0612345678',
                'dst' => '0140000000',
                'calldate' => '2026-10-19 10:00:00',
                'recordingfile' => $reference,
            ]]);

            $call = $provider->search($this->query())[0];

            $this->assertNull($call->recordingProviderReference, $reference);
            $this->assertSame(CallRecord::RECORDING_UNAVAILABLE, $call->recordingState, $reference);
        }
    }

    public function test_disabled_issabel_search_fails_with_clear_configuration_error(): void
    {
        config(['telephony.issabel.enabled' => false]);
        $provider = new IssabelCallProvider(new class implements IssabelCdrReader
        {
            public function search(CallSearchQuery $query): array
            {
                return [];
            }
        });

        $this->expectException(TelephonyException::class);
        $this->expectExceptionMessage('ISSABEL_ENABLED');
        $provider->search($this->query());
    }

    public function test_capabilities_are_explicit(): void
    {
        config([
            'telephony.issabel.enabled' => true,
            'telephony.issabel.recording_root' => '/tmp/recordings',
        ]);

        $capabilities = $this->provider([])->capabilities();
        $this->assertTrue($capabilities['search']);
        $this->assertTrue($capabilities['recording_download']);
        $this->assertTrue($capabilities['recording_reference']);
    }
}
