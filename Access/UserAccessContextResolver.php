<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Access;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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

        $userExists = ! Schema::hasTable('users') || DB::table('users')->where('user_id', $userId)->exists();
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
            $active = $active && $tenantActive && (bool) DB::table('users')
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->exists();
        }

        return new UserAccessContext(
            tenantId: $tenantId,
            userId: $userId,
            active: $active,
            credits: $credits,
            rights: array_values(array_unique(array_map('strval', $rights))),
        );
    }
}
