<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Access;

final readonly class UserResourcePolicy
{
    public function __construct(
        public int $tenantId,
        public ?int $ownerUserId = null,
        public ?string $requiredRight = null,
        public ?string $minimumLevel = null,
        public int $creditsRequired = 0,
        public string $policyVersion = '1',
        public ?string $requiredScoreVersion = null,
    ) {}
}
