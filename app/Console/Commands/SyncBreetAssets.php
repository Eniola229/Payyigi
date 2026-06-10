<?php

namespace App\Console\Commands;

use App\Models\BreetAsset;
use App\Services\Breet\BreetService;
use Illuminate\Console\Command;

class SyncBreetAssets extends Command
{
    protected $signature   = 'breet:sync-assets';
    protected $description = 'Sync supported deposit assets from Breet API into breet_assets table';

    public function handle(BreetService $breet): int
    {
        $this->info('Fetching assets from Breet...');

        try {
            $assets = $breet->getDepositAssets();
        } catch (\Exception $e) {
            $this->error('Failed: ' . $e->getMessage());
            return self::FAILURE;
        }

        $activeIds = [];
        $synced    = 0;

        foreach ($assets as $asset) {
            BreetAsset::updateOrCreate(
                ['id' => $asset['id']],
                [
                    'symbol'           => strtoupper($asset['symbol']          ?? ''),
                    'name'             => $asset['name']                       ?? '',
                    'identifier'       => $asset['identifier']                 ?? '',
                    'network'          => $asset['network']                    ?? null,
                    'type'             => $asset['type']                       ?? null,
                    'icon'             => $asset['icon']                       ?? null,
                    'tx_link'          => $asset['txLink']                     ?? null,
                    'minimum'          => $asset['minimum']                    ?? 0,
                    'flag_fee_usd'     => $asset['flagFeeUSD']                 ?? 0,
                    'is_account_based' => $asset['isAccountBased']             ?? true,
                    'is_active'        => true,
                ]
            );

            $activeIds[] = $asset['id'];
            $synced++;
        }

        $deactivated = BreetAsset::whereNotIn('id', $activeIds)->update(['is_active' => false]);

        $this->info("Synced {$synced} assets. Deactivated {$deactivated} stale assets.");

        return self::SUCCESS;
    }
}