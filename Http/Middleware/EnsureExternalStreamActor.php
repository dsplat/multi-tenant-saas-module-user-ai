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
 * 外部主体流式入口闸（User 端 AI 流式契约的唯一公开入口）
 *
 * 与同步入口闸 {@see EnsureExternalActor} 同职责四件事（解析租户 / 租户级模块
 * 门控 / 设置 ActorContext / 结束清理），差异只有一处：**租户来源**。
 *
 * 为什么来源不同：
 *   同步 ask 由浏览器直接打 PHP，请求体自带 tenant_slug；而流式链路的浏览器
 *   打的是 Node 引擎（/ai-stream/chat），Node 再回调 PHP 契约端点。租户标识
 *   由浏览器经 X-Tenant-ID 头送到 Node，Node 透传回 PHP——请求体里没有 slug，
 *   只有头。故本闸按 X-Tenant-ID 解析租户。
 *
 * ⚠ 与 EnsureExternalActor 一样：本闸必须设置在 ActorContext，否则
 *   ToolRegistry 暴露层闸门会静默跳过（fail-open），外部主体就能触达
 *   operator 专属工具。新增任何流式公开路由都必须带本中间件。
 */
class EnsureExternalStreamActor
{
    public function __construct(private readonly ModuleManager $moduleManager) {}

    public function handle(Request $request, Closure $next): Response
    {
        // ① 解析租户：流式契约端点无 tenant.identify，须显式按 X-Tenant-ID 解析
        $tenantId = $request->header('X-Tenant-ID');

        if (! is_string($tenantId) || trim($tenantId) === '') {
            return response()->json([
                'success' => false,
                'message' => '缺少 X-Tenant-ID 头',
            ], 422);
        }

        $tenant = Tenant::where('tenant_id', trim($tenantId))
            ->where('status', 'active')
            ->first(['tenant_id', 'name', 'slug']);

        if ($tenant === null) {
            return response()->json([
                'success' => false,
                'message' => '租户不存在或未激活',
            ], 404);
        }

        $tenantId = (int) $tenant->tenant_id;

        // TenantContext：ToolRegistry / Knowledge / 用量记账都依赖它
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
