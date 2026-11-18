<?php

namespace App\Console\Commands;

use App\Models\AdminLog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;

#[Signature('admin-logs:prune')]
#[Description('Delete administrator audit logs older than the configured retention period')]
class PruneAdminLogs extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $days = filter_var(config('admin_logs.retention_days'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($days === false) {
            $this->error('admin_logs.retention_days must be a positive integer.');

            return self::FAILURE;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;
        AdminLog::query()->where('created_at', '<', $cutoff)->select('id')
            ->chunkById(1000, function (Collection $logs) use ($cutoff, &$deleted): void {
                $deleted += AdminLog::query()->whereKey($logs->modelKeys())->where('created_at', '<', $cutoff)->delete();
            });
        $this->info('Pruned '.$deleted.' administrator audit logs.');

        return self::SUCCESS;
    }
}
