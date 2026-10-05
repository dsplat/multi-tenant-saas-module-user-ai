<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Access;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Context\ActorContext;

/**
 * 从服务端身份与租户成员关系派生 UserAccessContext。
 *
 * rights 只能由受信业务服务传入（例如 course_entitlements），不接受请求参数。
 */
final class UserAccessContextResolver
{
    public function resolve(int $tenantId, int $userId, array $rights = []): UserAccessContext
    {
        $active = true;
        $credits = 0;
        $level = ActorContext::LEVEL_AUTHENTICATED;

        $user = null;
        $userExists = ! Schema::hasTable('users') || ($user = DB::table('users')
            ->where('user_id', $userId)
            ->first(['is_active', 'email_verified_at', 'phone_verified_at'])) !== null;
        $tenantActive = ! Schema::hasTable('tenants') || DB::table('tenants')
            ->where('tenant_id', $tenantId)
            ->where('status', 'active')
            ->exists();

        if (Schema::hasTable('tenant_users') && $userExists) {
            $membership = DB::table('tenant_users')
                ->where('tenant_id', $tenantId)
                ->where('user_id', $userId)
                ->first(['is_active', 'credits']);

            $active = $tenantActive && $membership !== null && (bool) $membership->is_active;
            $credits = $membership ? (int) $membership->credits : 0;
        }

        if (Schema::hasTable('users') && $userExists) {
            $active = $active && $tenantActive && (bool) $user->is_active;
            if ($user->email_verified_at !== null || $user->phone_verified_at !== null) {
                $level = ActorContext::LEVEL_VERIFIED;
            }
        }

        // ActorContext 是请求级服务端事实；只接受同一主体，绝不从请求参数读取等级。
        if (ActorContext::getId() === (string) $userId && in_array(ActorContext::getLevel(), ActorContext::LEVELS, true)) {
            $level = (string) ActorContext::getLevel();
        }

        $rights = array_values(array_unique(array_filter(
            array_map('strval', $rights),
            static fn (string $right): bool => $right !== ''
        )));

        return new UserAccessContext(
            tenantId: $tenantId,
            userId: $userId,
            active: $active,
            level: $level,
            credits: max(0, $credits),
            rights: $rights,
        );
    }
}
