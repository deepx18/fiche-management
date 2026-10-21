<?php

declare(strict_types=1);

namespace App\Services\Telephony\Issabel;

use App\Models\CallRecord;
use App\Services\Telephony\CallProvider;
use App\Services\Telephony\CallSearchQuery;
use App\Services\Telephony\ProviderCall;
use App\Services\Telephony\TelephonyException;
use App\Services\Telephony\TelephonyNumber;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Adaptateur Issabel.
 *
 * Source de vérité : la table CDR Asterisk/Issabel, lue en lecture seule. Un
 * enregistrement n'est jamais téléchargé ici : le CDR fournit au mieux un nom
 * de fichier (`recordingfile`), récupéré ensuite par le service dédié depuis
 * un répertoire privé configuré.
 */
class IssabelCallProvider implements CallProvider
{
    public function __construct(private readonly IssabelCdrReader $reader) {}

    public function name(): string
    {
        return 'issabel';
    }

    public function supportsSearch(): bool
    {
        return (bool) config('telephony.issabel.enabled', false);
    }

    public function capabilities(): array
    {
        return [
            'search' => $this->supportsSearch(),
            'recording_download' => (string) config('telephony.issabel.recording_root', '') !== '',
            'recording_reference' => true,
            'ingest' => 'cdr_database',
        ];
    }

    /** @return list<ProviderCall> */
    public function search(CallSearchQuery $query): array
    {
        if (! $this->supportsSearch()) {
            throw TelephonyException::configuration(
                'Issabel n\'est pas activé. Renseignez ISSABEL_ENABLED=true et la connexion CDR.'
            );
        }

        $calls = [];

        foreach ($this->reader->search($query) as $row) {
            $call = $this->map($row, $query->e164);
            if ($call !== null) {
                $calls[] = $call;
            }
        }

        return $calls;
    }

    /** @param array<string, mixed> $row */
    private function map(array $row, string $targetE164): ?ProviderCall
    {
        $src = $this->string($row['src'] ?? null);
        $dst = $this->string($row['dst'] ?? null);

        $srcMatches = TelephonyNumber::matches($src, $targetE164);
        $dstMatches = TelephonyNumber::matches($dst, $targetE164);

        // Une ligne retournée par la requête doit correspondre réellement à
        // la cible après normalisation ; aucune association approximative.
        if (! $srcMatches && ! $dstMatches) {
            return null;
        }

        $uniqueId = $this->string($row['uniqueid'] ?? null);
        $providerCallId = $uniqueId ?? $this->fingerprint($row);

        $duration = $this->int($row['duration'] ?? null);
        $billsec = $this->int($row['billsec'] ?? null);
        $startedAt = $this->date($row['calldate'] ?? null);
        $agentColumn = (string) config('telephony.issabel.agent_column', '');
        $directionColumn = (string) config('telephony.issabel.direction_column', '');
        $recordingReference = $this->recordingReference($row['recordingfile'] ?? null);

        return new ProviderCall(
            providerCallId: $providerCallId,
            rawPhone: $srcMatches ? $src : $dst,
            rawDestination: $dst,
            matchedPhoneE164: $targetE164,
            direction: $directionColumn !== '' ? $this->string($row[$directionColumn] ?? null) : null,
            status: $this->string($row['disposition'] ?? null),
            startedAt: $startedAt,
            endedAt: null, // le CDR fournit une durée, pas une fin fiable
            durationSeconds: $duration,
            billableSeconds: $billsec,
            agent: $agentColumn !== '' ? $this->string($row[$agentColumn] ?? null) : null,
            recordingState: $recordingReference !== null
                ? CallRecord::RECORDING_REFERENCE_ONLY
                : CallRecord::RECORDING_UNAVAILABLE,
            recordingProviderReference: $recordingReference,
            metadata: array_filter([
                'linkedid' => $this->string($row['linkedid'] ?? null),
            ], static fn (mixed $v): bool => $v !== null),
        );
    }

    /**
     * Empreinte de secours si l'installation n'expose pas `uniqueid`.
     * Elle combine les champs stables du CDR ; elle est documentée dans
     * docs/phase-2-telephony.md et ne déduplique jamais par numéro seul.
     *
     * @param  array<string, mixed>  $row
     */
    private function fingerprint(array $row): string
    {
        return 'issabel-fp-'.hash('sha256', implode('|', [
            (string) ($row['calldate'] ?? ''),
            (string) ($row['src'] ?? ''),
            (string) ($row['dst'] ?? ''),
            (string) ($row['duration'] ?? ''),
            (string) ($row['billsec'] ?? ''),
        ]));
    }

    /**
     * Conserve un chemin relatif sûr fourni par le CDR, jamais une URL ni une
     * composante de traversal. Le répertoire racine reste exclusivement côté
     * serveur (`ISSABEL_RECORDING_ROOT`).
     *
     * Certaines installations Issabel enregistrent un chemin absolu dans
     * `recordingfile` (ex. /var/spool/asterisk/monitor/2026/10/20/out-...wav)
     * alors que les fichiers ont été déplacés sous `ISSABEL_RECORDING_ROOT`.
     * On accepte alors le chemin absolu en extraisant la partie relative
     * correspondant à la structure de répertoires (YYYY/MM/DD/fichier).
     */
    private function recordingReference(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);
        if ($value === '' || str_contains($value, "\0")) {
            return null;
        }

        $value = str_replace('\\', '/', $value);

        // URL ou chemin Windows absolu : refusé.
        if (preg_match('/^[A-Za-z]:/', $value) === 1) {
            return null;
        }

        if (str_contains($value, '://')) {
            return null;
        }

        // Chemin absolu : extraire la partie relative en conservant la
        // structure YYYY/MM/DD/fichier typique d'Asterisk.
        if (str_starts_with($value, '/')) {
            $parts = explode('/', $value);
            // On cherche la première composante qui ressemble à une année
            // (20XX) et on conserve tout à partir de ce point.
            foreach ($parts as $i => $part) {
                if (preg_match('/^20\d{2}$/', $part)) {
                    $value = implode('/', array_slice($parts, $i));
                    break;
                }
            }

            // Si aucune année n'est trouvée, on prend les 4 derniers
            // composants (MM/DD/fichier ou YYYY/MM/DD/fichier).
            if (str_starts_with($value, '/')) {
                $parts = array_values(array_filter(explode('/', $value), static fn (string $p): bool => $p !== ''));
                $value = implode('/', array_slice($parts, -4));
            }
        }

        foreach (explode('/', $value) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return null;
            }
        }

        return mb_substr($value, 0, 190);
    }

    private function date(mixed $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse((string) $value);
        } catch (Throwable) {
            return null;
        }
    }

    private function int(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    private function string(mixed $value): ?string
    {
        if ($value === null || ! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
