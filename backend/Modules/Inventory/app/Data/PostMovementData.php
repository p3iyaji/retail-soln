<?php

namespace Modules\Inventory\Data;

use Modules\Inventory\Enums\BalanceBucket;
use Modules\Inventory\Enums\LocationType;
use Modules\Inventory\Enums\MovementType;

final class PostMovementData
{
    public function __construct(
        public readonly int $organizationId,
        public readonly LocationType $locationType,
        public readonly int $locationId,
        public readonly int $productVariantId,
        public readonly MovementType $type,
        public readonly string $quantity,
        public readonly BalanceBucket $bucket = BalanceBucket::Available,
        public readonly ?string $unitCost = null,
        public readonly ?string $referenceType = null,
        public readonly ?int $referenceId = null,
        public readonly ?int $userId = null,
        public readonly ?string $notes = null,
        public readonly ?string $uuid = null,
    ) {}
}
