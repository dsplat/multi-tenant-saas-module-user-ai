<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\Concerns\BindsExecutionIdentity;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleManager;
use MultiTenantSaas\Modules\UserAi\Services\ExternalTenantAccess;
use Symfony\Component\HttpFoundation\Response;

/**
 * User AI 流式入口：解析租户、模块门控、认证及有效成员归属校验、装载执行身份、请求结束清理。
 * 客户端租户标识只负责定位，授权统一交给 ExternalTenantAccess。
 * 无匿名降级：知识库尚无逐连接公开范围，默认不得匿名读取。
 *
 * 执行身份（type=user, surface=user_ai）在认证 + 租户授权完成后装载，含匿名形态。
 * Node 回环三端点（resolve/tools/usage）各自成请求，身份随请求装载/销毁；
 * 若下游返回 StreamedResponse，续执行由 continuation 句柄覆盖（playbook §7）。
 */
class EnsureExternalStreamActor
{
    use BindsExecutionIdentity;

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

        try {
            // ③ 入口是身份边界起点：先清掉可能残留的上文（A 包冲突装载会拒绝），
            //    再认证 —— 身份由本入口按有效凭据重新装载，不继承调用前残留。
            ActorContext::clear();

            // Authenticate and authorize before tools, model credentials or quota operations.
            $this->accessOrResolve()->authorize($request, $tenantId);

            // ④ 认证 + 租户授权完成后装载执行身份（type=user, surface=user_ai）。
            // authorize 内部经 ActorContext::set 建过隐式投影项，此处收束为入口显式作用域。
            $input = $this->userAiInput(
                subjectId: ActorContext::getId(),
                level: ActorContext::getLevel(),
                tenantId: (string) $tenantId,
                channelIdentity: ActorContext::getChannelIdentity(),
                authSource: 'sanctum',
            );
            ActorContext::clear();

            return $this->runWithIdentity($input, $request, $next);
        } finally {
            // ⑤ 请求结束即清租户上下文；身份作用域归 runRoot/流式 continuation 销毁
            TenantContext::clear();
        }
    }
}
