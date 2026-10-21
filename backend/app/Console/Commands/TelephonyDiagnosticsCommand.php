<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Telephony\TelephonyDiagnostics;
use Illuminate\Console\Command;

/**
 * Test Issabel and/or Kavkom telephony connections without launching a search.
 *
 * Usage:
 *   php artisan telephony:diagnostics
 *   php artisan telephony:diagnostics kavkom
 *   php artisan telephony:diagnostics issabel
 *
 * The command is read-only: no CDR is created, no secret is emitted,
 * and no network request beyond the minimal checks is performed.
 */
final class TelephonyDiagnosticsCommand extends Command
{
    protected $signature = 'telephony:diagnostics
        {provider? : Provider to test (kavkom | issabel). Defaults to both.}
        {--raw : Output JSON instead of a formatted table}';

    protected $description = 'Test Issabel and/or Kavkom telephony connections in read-only mode';

    public function handle(TelephonyDiagnostics $diagnostics): int
    {
        $providerArg = $this->argument('provider');

        $providers = $providerArg
            ? [$providerArg]
            : ['kavkom', 'issabel'];

        $output = $this->output;

        $output->writeln('<info>Telephony connection diagnostics</info>');
        $output->newLine();

        $statusLabels = [
            TelephonyDiagnostics::STATUS_OK => '<comment>OK</comment>',
            TelephonyDiagnostics::STATUS_WARNING => '<comment>WARNING</comment>',
            TelephonyDiagnostics::STATUS_ERROR => '<error>ERROR</error>',
        ];

        foreach ($providers as $provider) {
            if (! in_array($provider, TelephonyDiagnostics::PROVIDERS, true)) {
                $output->error(sprintf('Unknown provider %s. Available: %s', $provider, implode(', ', TelephonyDiagnostics::PROVIDERS)));

                return self::FAILURE;
            }

            $data = $diagnostics->run($provider);

            $output->newLine();
            $output->info(sprintf('Provider: %s', $data['provider']));
            $output->info(sprintf('Status: %s', $statusLabels[$data['status']] ?? $data['status']));
            $output->info(sprintf('Checked at: %s', $data['checked_at']));
            $output->info(sprintf('Duration: %d ms', $data['duration_ms']));

            foreach ($data['checks'] as $check) {
                $checkStatusLabel = $statusLabels[$check['status']] ?? $check['status'];
                $output->info(sprintf('  [%-8s] %s: %s', $check['key'], $checkStatusLabel, $check['message']));
                if (isset($check['code'])) {
                    $output->info(sprintf('         (code: %s)', $check['code']));
                }
            }
        }

        return self::SUCCESS;
    }
}