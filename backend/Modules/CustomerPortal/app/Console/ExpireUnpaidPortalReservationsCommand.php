<?php

namespace Modules\CustomerPortal\Console;

use Illuminate\Console\Command;
use Modules\CustomerPortal\Services\PortalCheckoutService;

class ExpireUnpaidPortalReservationsCommand extends Command
{
    protected $signature = 'portal:expire-unpaid-reservations
                            {--ttl= : Override reservation TTL minutes}';

    protected $description = 'Cancel unpaid portal orders past reservation TTL and release reserved stock';

    public function handle(PortalCheckoutService $checkout): int
    {
        $ttlOption = $this->option('ttl');
        $ttl = null;
        if ($ttlOption !== null && $ttlOption !== '') {
            if (! ctype_digit((string) $ttlOption) || (int) $ttlOption < 1) {
                $this->error('Option --ttl must be a positive integer.');

                return self::FAILURE;
            }
            $ttl = (int) $ttlOption;
        }

        $expired = $checkout->expireUnpaidReservations($ttl);
        $this->info("Expired {$expired} unpaid portal order reservation(s).");

        return self::SUCCESS;
    }
}
