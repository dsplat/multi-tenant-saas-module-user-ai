<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * 外部主体入口闸（User 端 AI 的唯一公开入口）
 *
 * 本中间件是**外部请求的单一声明点**，职责四件事：
 *   ① 解析租户（公开路由无 tenant.identify，须显式按 tenant_slug 解析）
 *   ② 租户级模块门控（本模块 tenant_toggleable=true）
 *   ③ **确保 ActorContext 已设置**（默认 anonymous）—— 暴露层闸门的前提
 *   ④ 请求结束清理上下文（finally）
 *
 * 为什么 ③ 必须在这里而不是让各调用方自己设：
 *   ToolRegistry 的暴露层闸门按 `ActorContext::hasExplicitActor()` 判定。
 *   若外部路径忘了设置 ActorContext，闸门会**静默跳过**（fail-open）——
 *   与「执法点依赖调用方自觉」是同一类问题。
 *   把设置点收敛到本中间件后，「外部请求未声明主体」在结构上不可能发生；
 *   配套的接线测试（UserAiWiringTest）会拦截任何忘记挂本中间件的新公开路由。
 *
 * 未来扩展：已登录链路（authenticated / verified）在 ③ 之前按渠道身份
 * 解析并调用 ActorContext::set() 覆盖默认的 anonymous 即可，闸门逻辑无需改动。
 */
class EnsureExternalActor
{
    public function __construct(private readonly ModuleManager $moduleManager) {}

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

        // ③ 外部主体上下文：默认匿名；已登录链路在此之后覆盖为更高等级
        if (! ActorContext::hasExplicitActor()) {
            $visitorKey = $request->input('visitor_key');

            ActorContext::setAnonymous(
                is_string($visitorKey) && $visitorKey !== '' ? $visitorKey : null
            );
        }

        try {
            return $next($request);
        } finally {
            // ④ 请求结束即清，避免污染同进程后续请求（Octane / queue 伪实例场景）
            ActorContext::clear();
            TenantContext::clear();
        }
    }
}
