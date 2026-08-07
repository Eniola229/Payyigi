<?php

namespace App\Console\Commands;

use App\Models\Transaction;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireStaleAwaitingCryptoTransactions extends Command
{
    protected $signature = 'transactions:expire-stale-sell
                            {--dry-run : List what would be expired without changing anything}
                            {--hours=24 : Age threshold in hours}';

    protected $description = 'Mark sell transactions stuck in awaiting_crypto for over N hours as expired';

    public function handle(): int
    {
        $hours  = (int) $this->option('hours');
        $cutoff = now()->subHours($hours);

        $query = Transaction::where('type', 'sell')
            ->where('status', 'awaiting_crypto')
            ->where('created_at', '<', $cutoff);

        $count = $query->count();

        if ($count === 0) {
            $this->info("No stale awaiting_crypto transactions found (older than {$hours}h).");
            return self::SUCCESS;
        }

        $this->info("Found {$count} transaction(s) in awaiting_crypto older than {$hours}h.");

        if ($this->option('dry-run')) {
            $query->get(['id', 'reference', 'user_id', 'created_at'])
                ->each(fn ($txn) => $this->line("[dry-run] would expire {$txn->reference} (user {$txn->user_id}, created {$txn->created_at})"));
            return self::SUCCESS;
        }

        $query->chunkById(100, function ($transactions) use ($hours) {
            foreach ($transactions as $txn) {
                $txn->update([
                    'status'         => 'expired',
                    'expired_at'     => now(),
                    'failure_reason' => "No deposit received within {$hours}h — auto-expired.",
                ]);

                Log::info('Expired stale awaiting_crypto sell transaction', [
                    'reference'       => $txn->reference,
                    'user_id'         => $txn->user_id,
                    'created_at'      => $txn->created_at,
                    'breet_wallet_id' => $txn->breet_wallet_id,
                ]);
            }
        });

        $this->info("Expired {$count} transaction(s).");
        return self::SUCCESS;
    }
}