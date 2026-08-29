<?php

namespace Modules\Notifications\Data;

/**
 * Who a notification is addressed to, independent of the channel used.
 *
 * `type` records what kind of principal the row belongs to (`user` for staff,
 * `customer_account` for portal shoppers) so the staff inbox can scope reads
 * without joining across modules.
 */
final class NotificationRecipient
{
    public const STAFF_USER = 'user';

    public const CUSTOMER_ACCOUNT = 'customer_account';

    public function __construct(
        public readonly string $type,
        public readonly ?int $id = null,
        public readonly ?string $email = null,
        public readonly ?string $phone = null,
        public readonly ?string $name = null,
    ) {}

    public static function staff(int $userId, ?string $email = null, ?string $name = null): self
    {
        return new self(self::STAFF_USER, $userId, $email, null, $name);
    }

    public static function customerAccount(
        int $accountId,
        ?string $email = null,
        ?string $phone = null,
        ?string $name = null,
    ): self {
        return new self(self::CUSTOMER_ACCOUNT, $accountId, $email, $phone, $name);
    }

    public function addressFor(string $channel): ?string
    {
        return match ($channel) {
            'email' => $this->email,
            'sms' => $this->phone,
            default => null,
        };
    }
}
