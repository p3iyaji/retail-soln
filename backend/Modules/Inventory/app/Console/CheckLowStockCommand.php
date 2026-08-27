<?php

namespace Modules\Inventory\Console;

use Illuminate\Console\Command;
use Modules\Inventory\Services\LowStockAlertService;
use Modules\Organization\Services\OrganizationService;

class CheckLowStockCommand extends Command
{
    private const SAMPLE_LIMIT = 50;

    protected $signature = 'inventory:check-low-stock
                            {--storeId= : Limit to a store location}
                            {--warehouseId= : Limit to a warehouse location}';

    protected $description = 'Scan inventory balances for low-stock alerts (available ≤ reorder level)';

    public function handle(LowStockAlertService $alerts, OrganizationService $organizations): int
    {
        $storeId = $this->parsePositiveIntOption('storeId');
        if ($storeId === false) {
            return self::FAILURE;
        }

        $warehouseId = $this->parsePositiveIntOption('warehouseId');
        if ($warehouseId === false) {
            return self::FAILURE;
        }

        if ($storeId !== null && $warehouseId !== null) {
            $this->error('Options --storeId and --warehouseId are mutually exclusive.');

            return self::FAILURE;
        }

        $organizationId = $organizations->current()->id;

        $total = $alerts->count($organizationId, $storeId, $warehouseId);
        $critical = $alerts->query($organizationId, $storeId, $warehouseId)
            ->where('inventory_balances.available', '<=', 0)
            ->count();
        $warning = $total - $critical;

        $this->info("Low-stock check complete for organization {$organizationId}.");
        $this->line("Total: {$total} (warning: {$warning}, critical: {$critical})");

        $sample = $alerts->query($organizationId, $storeId, $warehouseId)
            ->limit(self::SAMPLE_LIMIT)
            ->get();
        foreach ($sample as $balance) {
            $severity = $alerts->severityFor((string) $balance->available);
            $sku = $balance->variant?->sku ?? (string) $balance->product_variant_id;
            $this->line(sprintf(
                '  [%s] %s available=%s reorder=%s @ %s:%s',
                $severity,
                $sku,
                $balance->available,
                $balance->variant?->product?->reorder_level,
                $balance->location_type,
                $balance->location_id,
            ));
        }

        if ($total > self::SAMPLE_LIMIT) {
            $this->line(sprintf('  … and %d more', $total - self::SAMPLE_LIMIT));
        }

        return self::SUCCESS;
    }

    /**
     * @return int|null|false null when unset; int when valid; false on invalid input
     */
    private function parsePositiveIntOption(string $name): int|false|null
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! ctype_digit((string) $raw) || (int) $raw < 1) {
            $this->error("Option --{$name} must be a positive integer.");

            return false;
        }

        return (int) $raw;
    }
}
