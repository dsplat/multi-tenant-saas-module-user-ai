<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Context\TrustedExecutionInput;
use MultiTenantSaas\Modules\Infrastructure\Http\Middleware\Concerns\BindsExecutionIdentity;
use MultiTenantSaas\Modules\Infrastructure\Models\Tenant;
use MultiTenantSaas\Modules\Infrastructure\Services\ModuleManager;
use MultiTenantSaas\Modules\UserAi\Services\ExternalTenantAccess;
use MultiTenantSaas\Modules\UserAi\Services\UserAiProfileSource;
use Symfony\Component\HttpFoundation\Response;

/**
 * User AI 入口：解析租户、模块门控、认证及有效成员归属校验、装载执行身份、请求结束清理。
 * 客户端租户标识只负责定位，授权统一交给 ExternalTenantAccess。
 * 无匿名降级：知识库尚无逐连接公开范围，默认不得匿名读取。
 *
 * 执行身份（type=user, surface=user_ai）在认证 + 租户授权完成后装载，含匿名形态
 * （subjectId=null + level=anonymous 为已装载主体）。surface 由本入口常量赋值。
 * 身份作用域由 runRoot / 流式 continuation 销毁；本中间件 finally 只清租户上下文。
 */
class EnsureExternalActor
{
    use BindsExecutionIdentity;

    public function __construct(
        private readonly ModuleManager $moduleManager,
        private readonly ?ExternalTenantAccess $access = null,
        private readonly ?UserAiProfileSource $profiles = null,
    ) {}

    private function accessOrResolve(): ExternalTenantAccess
    {
        return $this->access ?? app(ExternalTenantAccess::class);
    }

    private function profilesOrResolve(): UserAiProfileSource
    {
        return $this->profiles ?? app(UserAiProfileSource::class);
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
            // ③ 入口是身份边界起点：先清掉可能残留的上文（A 包冲突装载会拒绝），
            //    再认证 —— 身份由本入口按有效凭据重新装载，不继承调用前残留。
            ActorContext::clear();

            // Authenticate and authorize before tools, model credentials or quota operations.
            $this->accessOrResolve()->authorize($request, $tenantId);

            // ④ 认证 + 租户授权完成后装载执行身份。
            // authorize 内部经 ActorContext::set 建过隐式投影项，此处收束为入口显式作用域。
            $input = $this->userAiInput(
                subjectId: ActorContext::getId(),
                level: ActorContext::getLevel(),
                tenantId: (string) $tenantId,
                channelIdentity: ActorContext::getChannelIdentity(),
                authSource: 'sanctum',
            );
            ActorContext::clear();

            // ⑤ 租户画像（可选）：工具面只收窄。必须在身份装载之后才生效——
            //    ActorContext::setToolScope 在未装载时只会暂存（pending），故这里把
            //    收窄直接写进不可变执行输入（流式 continuation 重建时也不丢）。
            //    profile 未绑定/未声明 toolSurface 时完全不收窄（BC）。
            $profiles = $this->profilesOrResolve();
            $profile = $profiles->forTenant($tenantId);
            $surface = $profiles->toolSurface($profile);

            if ($surface !== null) {
                $input = new TrustedExecutionInput(identity: $input->identity->narrowToolScope($surface));
            }

            return $this->runWithIdentity($input, $request, $next);
        } finally {
            // ⑥ 请求结束即清租户上下文；身份作用域归 runRoot/流式 continuation 销毁
            TenantContext::clear();
        }
    }
}
