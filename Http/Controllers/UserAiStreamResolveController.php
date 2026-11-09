<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\IdGeneratorContract;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Exceptions\DomainException;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Ai\Services\AiUsageReservationService;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\AiStreaming\Http\Controllers\ResolveController;
use MultiTenantSaas\Modules\UserAi\Models\UserAiConversation;
use MultiTenantSaas\Modules\UserAi\Services\UserAiConversationService;
use MultiTenantSaas\Modules\UserAi\Services\UserAiProfileSource;
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
 *   租户画像（F2）绑定时再按 profile.toolSurface 收窄，**只减不增**。
 *   operator 的营销策划 / 客户管理等工具对 C 端不存在。
 * - 会话（F4）：`conversation_id` 由服务端续接或新建（独立 user_ai_conversations，
 *   不复用 operator conversations）；历史事实源以服务端为准，经 payload.history
 *   交给 reserveStream 裁剪成 context_messages。未传 id 时创建新会话。
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
        private readonly UserAiConversationService $conversations,
        private readonly UserAiProfileSource $profiles,
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

        $data = $request->validate([
            'conversation_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'source' => ['sometimes', 'nullable', 'string', 'in:' . UserAiConversation::SOURCE_H5 . ',' . UserAiConversation::SOURCE_MINIAPP],
            'messages' => ['sometimes', 'array', 'max:40'],
            'messages.*.role' => ['required', 'in:user,assistant'],
            'messages.*.content' => ['required', 'string', 'max:20000'],
        ]);

        $messages = $data['messages'] ?? [];

        // 归属只认服务端身份（Bearer 经入口闸装载），不接受请求体 user_id
        $userId = (int) (ActorContext::getId() ?? 0);
        if ($userId <= 0) {
            return response()->json([
                'success' => false,
                'message' => '请先登录后使用智能问答',
            ], 401);
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

        // 续接/新建会话；他人 / 不存在的 id 在此 404（不区分「无权」与「不存在」）
        $conversation = $this->conversations->resolve(
            $tenantId,
            $userId,
            $data['conversation_id'] ?? null,
            (string) ($data['source'] ?? UserAiConversation::SOURCE_H5),
        );

        // 服务端历史必须在**落本轮 user 消息之前**取，避免把本轮输入重复喂回模型
        $history = $this->conversations->history($conversation);

        // 租户画像（F2，可选）：只影响人格/UI/工具面，不参与等级与归属判定
        $profile = $this->profiles->forTenant($tenantId);
        $surface = $this->profiles->toolSurface($profile);

        // C 端工具面 = tool_surface 白名单（fail-closed），再按模块开通过滤；
        // profile 只在白名单之内收窄（交集为空 → 一个工具都不下发）。
        $allowed = array_keys((array) config('user-ai.tool_surface.allowed', []));
        $candidates = $surface ?? $allowed;

        $tools = $this->toolRegistry->getToolDefinitions(
            array_values(array_filter(
                $candidates,
                fn (string $slug) => $this->toolRegistry->isEnabledForTenant($slug, $tenantId),
            )),
        );

        $payload = [
            'request_id' => (string) $this->idGenerator->generate(),
            'tenant_id' => $tenantId,
            // C 端无 operator Agent 概念，agent_id 置 0 仅为对齐 Node payload shape
            'agent_id' => 0,
            // F4：C 端独立会话，由服务端续接/创建
            'conversation_id' => (int) $conversation->conversation_id,
            'provider' => $providerName,
            'model' => $modelName,
            'base_url' => rtrim((string) $baseUrl, '/'),
            'system_prompt' => $this->runtime->streamSystemPrompt($profile?->persona),
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'max_tool_calls' => (int) config('user-ai.stream.max_tool_calls', 2),
            'tools' => $tools,
        ];

        // reserveStream 按 payload.history + 本轮最后一条 user 消息裁剪出 context_messages，
        // Node 以它为唯一上下文（前端上行历史不再直接采信）。
        if ($history !== []) {
            $payload['history'] = $history;
        }

        try {
            app(AiUsageReservationService::class)
                ->reserveStream($payload, $messages, 'user-ai-stream', true);
        } catch (DomainException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], $exception->getStatusCode());
        }

        // 本轮最后一条 user 消息落库（assistant/tool 由 Node 流结束时上报）。
        // 放在预留成功之后：被配额/上下文门拒绝的请求不留半截会话消息。
        $lastUser = $this->lastUserMessage($messages);
        if ($lastUser !== null) {
            $this->conversations->appendUserMessage($conversation, $lastUser);
        }

        // direct 模式：下发 api_key（仅限 Node 与 PHP 同机/内网回环链路）
        if (config('ai-streaming.key_delivery', 'direct') === 'direct') {
            $payload['api_key'] = (string) ($providerConfig['api_key'] ?? $providerConfig['key'] ?? '');
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    /**
     * 本轮最后一条用户消息（无则 null）
     *
     * @param  array<int, array{role: string, content: string}>  $messages
     */
    private function lastUserMessage(array $messages): ?string
    {
        foreach (array_reverse($messages) as $message) {
            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $content = trim((string) ($message['content'] ?? ''));

            return $content === '' ? null : $content;
        }

        return null;
    }
}
