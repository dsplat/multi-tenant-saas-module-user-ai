<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
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
        if (! $request->bearerToken()) {
            abort(401, '请先登录后使用智能问答');
        }

        $guard = Auth::guard('sanctum');
        $guard->forgetUser();
        $guard->setRequest($request);
        $user = $guard->user();

        // No anonymous fallback for absent, invalid, expired or operator credentials.
        if (! $user instanceof User || ! $user->is_active) {
            abort(401, '请先登录后使用智能问答');
        }

        $accessToken = $user->currentAccessToken();
        $plainToken = $request->bearerToken();
        $secret = str_contains($plainToken, '|') ? explode('|', $plainToken, 2)[1] : $plainToken;
        // Reject Sanctum session fallback even when a bearer header is present.
        if (! $accessToken instanceof PersonalAccessToken
            || ! hash_equals($accessToken->token, hash('sha256', $secret))) {
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

        ActorContext::set((string) $user->getKey(), ActorContext::LEVEL_AUTHENTICATED);
    }
}
