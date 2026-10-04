<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Access;

final readonly class UserAccessContext
{
    public function __construct(
        public int $tenantId,
        public ?int $userId,
        public bool $active = true,
        public string $level = 'standard',
        public ?string $scoreVersion = null,
        public int $credits = 0,
        public array $rights = [],
    ) {}
}
