<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Services;

use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Modules\Auth\Models\User;
use MultiTenantSaas\Modules\Infrastructure\Models\TenantUser;
use MultiTenantSaas\Scopes\TenantScope;

/** Tenant selectors are context, never credentials. All public User AI endpoints share this gate. */
class ExternalTenantAccess
{
    public function authorize(Request $request, int $tenantId): void
    {
        // These public API callbacks use User bearer credentials, never a cached session identity.
        $plainToken = $request->bearerToken();

        if (! $plainToken) {
            abort(401, '请先登录后使用智能问答');
        }

        $secret = str_contains($plainToken, '|') ? explode('|', $plainToken, 2)[1] : $plainToken;

        // 不经 Auth::guard('sanctum')：Sanctum 先按 config('sanctum.guard') 解析会话
        // （vendor Guard::__invoke），同域后台 Cookie 里的 Operator 会顶掉合法的用户
        // Bearer，让真实 C 端用户拿到 401（BL-115）。Bearer 自己解析，会话身份根本不参与。
        $accessToken = PersonalAccessToken::findToken($secret);

        if (! $accessToken || ! $this->isWithinValidityWindow($accessToken)) {
            abort(401, '请先登录后使用智能问答');
        }

        // No anonymous fallback for absent, invalid, expired or operator credentials.
        $user = $accessToken->tokenable;
        if (! $user instanceof User || ! $user->is_active) {
            abort(401, '请先登录后使用智能问答');
        }

        $member = TenantUser::withoutGlobalScope(TenantScope::class)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $user->getKey())
            ->where('is_active', true)
            ->exists();

        if (! $member) {
            abort(403, '无权访问该租户的智能问答服务');
        }

        $user->withAccessToken($accessToken);

        ActorContext::set((string) $user->getKey(), ActorContext::LEVEL_AUTHENTICATED);
    }

    /**
     * 令牌时效与 Sanctum Guard::isValidAccessToken() 同口径：全局过期分钟 + 单令牌 expires_at
     */
    private function isWithinValidityWindow(PersonalAccessToken $accessToken): bool
    {
        $expiration = config('sanctum.expiration');
        if ($expiration && ! $accessToken->created_at?->gt(now()->subMinutes($expiration))) {
            return false;
        }

        return ! $accessToken->expires_at || ! $accessToken->expires_at->isPast();
    }
}
