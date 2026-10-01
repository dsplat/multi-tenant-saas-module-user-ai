<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use MultiTenantSaas\Contracts\AiTextServiceContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Modules\Ai\Services\Agent\AuditLogService;
use MultiTenantSaas\Modules\Ai\Services\Ai\ContentGuardService;
use MultiTenantSaas\Modules\Contracts\ModuleServiceProvider;
use MultiTenantSaas\Modules\UserAi\Services\OutboundContentGuard;
use MultiTenantSaas\Modules\UserAi\Services\UserAiRuntime;

/**
 * User AI 模块 —— 面向外部主体的 AI 基座
 *
 * 定位：框架第一条 User 端 AI 链路的信任边界与运行时。
 * 服务对象是外部用户（学生 / 家长 / 访客），不是 Operator。
 *
 * 依赖关系：
 * - Ai（ToolRegistry / ContentGuardService / AuditLogService / AiTextService）
 * - Knowledge（knowledge_search 工具的实际检索能力）
 * - Infrastructure（Tenant 模型用于租户解析）
 *
 * 与 operator 端 AI 的边界（铁律）：
 * - 不复用 agent_conversations（那是 Operator 体系）
 * - 不复用 AiStreaming / AssistantController 的运营者鉴权端点
 * - 工具必须经 ToolRegistry::execute() 咽喉，暴露层闸门在此生效
 *
 * 见 docs/user-ai-design.md 与 docs/security-control-checklist.md。
 */
class UserAiServiceProvider extends ModuleServiceProvider
{
    protected string $moduleName = 'user-ai';

    protected function registerModuleBindings(): void
    {
        $this->app->singleton(OutboundContentGuard::class, function () {
            return new OutboundContentGuard;
        });

        $this->app->singleton(UserAiRuntime::class, function (Container $app) {
            // AiTextService 可选：未绑定（纯框架部署 / AI 关闭）时置 null，
            // 运行时降级为直接返回检索片段（AI 可选性铁律）
            $aiText = $app->bound(AiTextServiceContract::class)
                ? $app->make(AiTextServiceContract::class)
                : null;

            $inboundGuard = $app->bound(ContentGuardService::class)
                ? $app->make(ContentGuardService::class)
                : new ContentGuardService;

            return new UserAiRuntime(
                toolRegistry: $app->make(ToolRegistryContract::class),
                inboundGuard: $inboundGuard,
                outboundGuard: $app->make(OutboundContentGuard::class),
                auditLog: $app->make(AuditLogService::class),
                aiText: $aiText,
            );
        });
    }

    protected function bootModule(): void
    {
        // public.php / tenant.php / api.php 由基类 loadModuleRoutes() 统一加载，
        // 此处不得重写（见 module-impl 约定：重写会导致双份注册 / 中间件缺失）

        // 限流器：路由用 `throttle:user-ai` 引用，速率来自配置。
        // 不硬编码在路由里，避免 config('user-ai.throttle.per_minute') 与路由参数漂移。
        RateLimiter::for('user-ai', function (Request $request) {
            return Limit::perMinute((int) config('user-ai.throttle.per_minute', 20))
                ->by($request->ip());
        });

        // 流式契约限流器：Node→PHP 走 127.0.0.1 回环，$request->ip() 恒为回环地址，
        // 若照此计数会把所有 C 端访客挤进同一桶（要么集体 429、要么限流失效）。
        // 故按 Node 从 x-forwarded-for 提取并透传的真实客户端 IP（X-Client-IP）分桶；
        // 头缺失时回落 $request->ip()（直连 PHP 的非回环场景仍可用）。
        RateLimiter::for('user-ai-stream', function (Request $request) {
            $clientIp = $request->header('X-Client-IP') ?: $request->ip();

            return Limit::perMinute((int) config('user-ai.throttle.per_minute', 20))
                ->by((string) $clientIp);
        });
    }
}
