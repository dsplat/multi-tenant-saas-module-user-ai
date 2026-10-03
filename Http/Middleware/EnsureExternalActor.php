<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleManager;
use MultiTenantSaas\Modules\UserAi\Services\ExternalTenantAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * User AI 入口：解析租户、模块门控、认证及有效成员归属校验、请求结束清理。
 * 客户端租户标识只负责定位，授权统一交给 ExternalTenantAccess。
 * 无匿名降级：知识库尚无逐连接公开范围，默认不得匿名读取。
 */
class EnsureExternalActor
{
    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ?ExternalTenantAccess $access = null,
    ) {}

    private function accessOrResolve(): ExternalTenantAccess
    {
        return $this->access ?? app(ExternalTenantAccess::class);
    }

    public function handle(Request $request, Closure $next): Response
    {
        // ① 解析租户：公开路由无 tenant.identify，必须显式指定
        $slug = $request->input('tenant_slug');

        if (! is_string($slug) || trim($slug) === '') {
            return response()->json([
                'success' => false,
                'message' => '缺少 tenant_slug 参数',
            ], 422);
        }

        $tenant = Tenant::where('slug', trim($slug))
            ->where('status', 'active')
            ->first(['tenant_id', 'name', 'slug']);

        if ($tenant === null) {
            return response()->json([
                'success' => false,
                'message' => '租户不存在或未激活',
            ], 404);
        }

        $tenantId = (int) $tenant->tenant_id;

        // TenantContext：ToolRegistry / Knowledge / 审计都依赖它
        TenantContext::setTenantId((string) $tenantId);
        $request->attributes->set('external_tenant', $tenant);

        // ② 租户级模块门控：不校验的话 tenant_toggleable 形同虚设
        if (! $this->moduleManager->isEnabledForTenant('user-ai', $tenantId)) {
            TenantContext::clear();

            return response()->json([
                'success' => false,
                'message' => '该租户未开通智能问答服务',
            ], 403);
        }

        try {
            // Authenticate and authorize before tools, model credentials or quota operations.
            $this->accessOrResolve()->authorize($request, $tenantId);

            return $next($request);
        } finally {
            // ④ 请求结束即清，避免污染同进程后续请求（Octane / queue 伪实例场景）
            ActorContext::clear();
            TenantContext::clear();
        }
    }
}
