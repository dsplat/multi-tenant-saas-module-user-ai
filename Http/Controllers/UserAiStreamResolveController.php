<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\AiStreaming\Http\Controllers\ResolveController;
use MultiTenantSaas\Modules\UserAi\Services\UserAiRuntime;

/**
 * User 端 AI 流式会话解析（Node SSE 引擎回调）
 *
 * 与 operator {@see ResolveController}
 * **产出同 shape** 的 payload，供同一 Node `streamText` 直接消费；差异是这条链路
 * 不经 operator Agent 体系，C 端能力面/护栏全部锁死在 UserAi 侧：
 *
 * - 工具面：只下发 `config('user-ai.tool_surface.allowed')` 白名单内的工具
 *   （现状仅 knowledge_search），并按 isEnabledForTenant 过滤未开通模块。
 *   operator 的营销策划 / 客户管理等工具对 C 端不存在。
 * - 会话：conversation_id 恒 null —— v1 C 端不落 operator 会话表（铁律：不复用
 *   agent_conversations），多轮靠前端回传 messages，Node 自然跳过 messages/report。
 * - 模型：读新增的 `config('user-ai.model.*')`（默认 bailian/qwen），与 operator
 *   secretary 配置解耦，避免耦合运营者账单口径。
 * - 成本：预算前置检查走 {@see AiUsageService}（按 TenantContext 记租户用量），
 *   与 operator 流式结算口径一致——流式 LLM 由 Node 直连、PHP 不经 AiGatewayService，
 *   故复用 AiUsageService 而非同步链路的网关扣费。此外叠加**主体级配额**前置
 *   （checkActorQuota，按 ActorContext 的主体），与租户总闸取严。
 *
 * 租户解析 / 模块门控 / ActorContext 设置全由 `EnsureExternalStreamActor` 中间件承担。
 */
class UserAiStreamResolveController extends BaseController
{
    public function __construct(
        private readonly ToolRegistryContract $toolRegistry,
        private readonly AiUsageService $usageService,
        private readonly UserAiRuntime $runtime,
        private readonly IdGeneratorContract $idGenerator,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! config('ai-streaming.enabled', true)) {
            abort(503, 'AI 流式服务已关闭');
        }

        $tenantId = (int) TenantContext::getId();

        // 配额/预算前置检查（超限即拒绝，不产生任何 LLM 开销）
        try {
            $this->usageService->checkQuota('text');
            $this->usageService->checkBudget();
            // 主体级配额（User 端）：与租户总闸并存、取严 —— 单主体不得刷爆租户额度
            $this->usageService->checkActorQuota('text');
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 402);
        }

        $providerName = (string) config('user-ai.model.provider', 'bailian');
        $modelName = (string) config('user-ai.model.model', 'qwen3.7-flash');
        $temperature = (float) config('user-ai.model.temperature', 0.3);
        $maxTokens = (int) config('user-ai.model.max_tokens', 2000);

        $providerConfig = (array) config("ai.providers.{$providerName}", []);
        $baseUrl = $providerConfig['base_url'] ?? $providerConfig['url'] ?? null;

        if (empty($baseUrl)) {
            return response()->json([
                'success' => false,
                'message' => "Provider [{$providerName}] 未配置 base_url",
            ], 422);
        }

        // C 端工具面 = tool_surface 白名单（fail-closed），再按模块开通过滤
        $allowed = array_keys((array) config('user-ai.tool_surface.allowed', []));
        $tools = $this->toolRegistry->getToolDefinitions(
            array_values(array_filter(
                $allowed,
                fn (string $slug) => $this->toolRegistry->isEnabledForTenant($slug, $tenantId),
            )),
        );

        $payload = [
            'request_id' => (string) $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            // C 端无 operator Agent 概念，agent_id 置 0 仅为对齐 Node payload shape
            'agent_id' => 0,
            // v1 不落服务端会话：Node 据 conversation_id=null 跳过 messages/report
            'conversation_id' => null,
            'provider' => $providerName,
            'model' => $modelName,
            'base_url' => rtrim((string) $baseUrl, '/'),
            'system_prompt' => $this->runtime->streamSystemPrompt(),
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'max_tool_calls' => (int) config('user-ai.stream.max_tool_calls', 2),
            'tools' => $tools,
        ];

        // direct 模式：下发 api_key（仅限 Node 与 PHP 同机/内网回环链路）
        if (config('ai-streaming.key_delivery', 'direct') === 'direct') {
            $payload['api_key'] = (string) ($providerConfig['api_key'] ?? $providerConfig['key'] ?? '');
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }
}
