<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Access;

final readonly class UserAccessDecision
{
    public function __construct(
        public bool $allowed,
        public string $reasonCode,
        public ?int $owner,
        public ?string $right,
        public string $level,
        public ?string $scoreVersion,
        public int $creditsRequired,
        public string $policyVersion,
    ) {}

    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
