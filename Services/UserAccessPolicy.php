<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Services;

use MultiTenantSaas\Contracts\UserAccessPolicyContract;
use MultiTenantSaas\Modules\UserAi\Access\UserAccessContext;
use MultiTenantSaas\Modules\UserAi\Access\UserAccessDecision;
use MultiTenantSaas\Modules\UserAi\Access\UserResourcePolicy;

final class UserAccessPolicy implements UserAccessPolicyContract
{
    public function decide(UserAccessContext $context, UserResourcePolicy $policy): UserAccessDecision
    {
        $decision = fn (bool $allowed, string $reason): UserAccessDecision => new UserAccessDecision(
            $allowed, $reason, $policy->ownerUserId, $policy->requiredRight, $context->level,
            $context->scoreVersion, $policy->creditsRequired, $policy->policyVersion,
        );

        if ($context->tenantId !== $policy->tenantId) {
            return $decision(false, 'tenant_mismatch');
        }
        if ($context->userId === null) {
            return $decision(false, 'identity_required');
        }
        if (! $context->active) {
            return $decision(false, 'identity_inactive');
        }
        if ($policy->minimumLevel !== null && $this->levelRank($context->level) < $this->levelRank($policy->minimumLevel)) {
            return $decision(false, 'level_insufficient');
        }
        if ($policy->ownerUserId !== null && $policy->ownerUserId !== $context->userId) {
            return $decision(false, 'owner_mismatch');
        }
        if ($policy->requiredRight !== null && ! in_array($policy->requiredRight, $context->rights, true)) {
            return $decision(false, 'right_missing');
        }
        if ($policy->creditsRequired > $context->credits) {
            return $decision(false, 'credits_insufficient');
        }

        return $decision(true, 'allowed');
    }

    private function levelRank(string $level): int
    {
        return array_search($level, ['anonymous', 'authenticated', 'verified', 'standard'], true) ?: 0;
    }
}
