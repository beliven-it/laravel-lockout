<?php

namespace Beliven\Lockout\Commands;

use Beliven\Lockout\Models\LockoutLog;
use Beliven\Lockout\Models\ModelLockout;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class PruneLockouts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * days-logs: override config lockout.prune.lockout_logs_days
     * days-models: override config lockout.prune.model_lockouts_days
     * --only-logs / --only-model : limit pruning to one table
     * --force : run without confirmation (useful for scheduled tasks)
     */
    protected $signature = 'lockout:prune
                            {--days-logs= : Number of days to retain lockout_logs (overrides config)}
                            {--days-models= : Number of days to retain model_lockouts (overrides config)}
                            {--only-logs : Prune only lockout_logs}
                            {--only-model : Prune only model_lockouts}
                            {--force : Do not ask for confirmation}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Prune old LockoutLog and ModelLockout records according to retention config';

    public function handle(): int
    {
        $pruneEnabled = config('lockout.prune.enabled', true);

        if (!$pruneEnabled) {
            $this->info('Pruning is disabled via configuration (lockout.prune.enabled = false).');

            return self::SUCCESS;
        }

        $daysLogs = $this->option('days-logs') !== null
            ? (int) $this->option('days-logs')
            : (int) config('lockout.prune.lockout_logs_days', 90);

        $daysModels = $this->option('days-models') !== null
            ? (int) $this->option('days-models')
            : (int) config('lockout.prune.model_lockouts_days', 365);

        $onlyLogs = $this->option('only-logs');
        $onlyModels = $this->option('only-model');

        if ($onlyLogs && $onlyModels) {
            $this->error('Options --only-logs and --only-model cannot be combined.');

            return self::FAILURE;
        }

        $this->line(sprintf('Prune configuration: logs=%d days, model_lockouts=%d days', $daysLogs, $daysModels));

        if (!$this->option('force')) {
            $confirmMsg = 'Proceed with pruning? This will permanently delete old records.';
            if (!$this->confirm($confirmMsg, false)) {
                $this->info('Aborted by user.');

                return self::SUCCESS;
            }
        }

        // Perform pruning operations
        try {
            if (!$onlyModels) {
                $deletedLogs = $this->pruneLockoutLogs($daysLogs);
                $this->info(sprintf('Pruned %d lockout_log(s) older than %d day(s).', $deletedLogs, $daysLogs));
            }

            if (!$onlyLogs) {
                $deletedModels = $this->pruneModelLockouts($daysModels);
                $this->info(sprintf('Pruned %d model_lockout(s) (unlocked records older than %d day(s)).', $deletedModels, $daysModels));
            }

            $this->info('Pruning complete.');

            return self::SUCCESS;
        } catch (\Throwable $e) {
            $this->error('An error occurred while pruning: ' . $e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Prune lockout_logs older than $days days.
     *
     * @return int Number of deleted records
     */
    protected function pruneLockoutLogs(int $days): int
    {
        $cutoff = Carbon::now()->subDays($days);

        // LockoutLog::where('attempted_at', '<', $cutoff)->delete();
        // Use model directly to ensure model events / casts are respected.
        return LockoutLog::where('attempted_at', '<', $cutoff)->delete();
    }

    /**
     * Prune model_lockouts history older than $days days.
     *
     * We prune only locks that have been unlocked (unlocked_at not null) and whose
     * unlocked_at is older than cutoff. This avoids accidentally deleting active locks.
     *
     * @return int Number of deleted records
     */
    protected function pruneModelLockouts(int $days): int
    {
        $cutoff = Carbon::now()->subDays($days);

        // Delete only records that have been unlocked and are older than cutoff.
        return ModelLockout::whereNotNull('unlocked_at')
            ->where('unlocked_at', '<', $cutoff)
            ->delete();
    }
}
