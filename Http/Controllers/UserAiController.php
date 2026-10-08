<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\UsageSettlementContract;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\Ai\Services\StreamUsageSettlementService;
use MultiTenantSaas\Modules\UserAi\Dto\UserAiContext;
use MultiTenantSaas\Modules\UserAi\Services\UserAiRuntime;

/**
 * User 端 AI 公开接口
 *
 * 边界职责（租户解析 / 模块门控 / 主体上下文）全部由 `EnsureExternalActor`
 * 中间件承担，控制器只做参数校验、配额前后置与呈现——见 Routes/public.php。
 *
 * 用量与成本（BL-116）：同步 ask 与流式链路同受配额约束，不能因为「走的是同步
 * 编排、没有 Node 回调」就无闸无账。本控制器在调用前做三闸前置（租户总闸 /
 * 预算 / 主体级硬闸），调用后把真实 token 消耗结算进租户级 + 主体级配额，口径
 * 与流式回调 {@see UserAiStreamUsageController} 一致。
 */
class UserAiController extends BaseController
{
    /**
     * 用后记账的原子单元（未显式注入时用自身已注入的用量服务与结算契约组装）。
     *
     * 与 {@see UserAiStreamUsageController} 同构：容器解析自动注入，直接构造
     * 控制器（单测）时仍走同一套协作者。
     */
    private readonly StreamUsageSettlementService $streamSettlement;

    public function __construct(
        private readonly UserAiRuntime $runtime,
        private readonly AiUsageService $usageService,
        private readonly UsageSettlementContract $settlement,
        ?StreamUsageSettlementService $streamSettlement = null,
    ) {
        $this->streamSettlement = $streamSettlement
            ?? new StreamUsageSettlementService($this->usageService, $this->settlement);
    }

    /**
     * 提问
     *
     * POST /api/v1/user-ai/ask
     * Body: { tenant_slug: string, question: string, visitor_key?: string }
     * 中间件：EnsureExternalActor（已解析租户、已设 ActorContext）
     */
    public function ask(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'tenant_slug' => ['required', 'string', 'max:100'],
            'question' => ['required', 'string', 'min:1', 'max:2000'],
            'visitor_key' => ['nullable', 'string', 'max:64'],
        ]);

        // 中间件已解析租户并写入 TenantContext；此处仅取回 ID
        $tenantId = (int) TenantContext::getId();

        // 配额/预算前置检查（超限即拒绝，不产生任何 LLM 开销）——与流式 resolve
        // 同一套闸：租户总闸 + 预算 + 主体级硬闸取严。BL-116：此前同步 ask 完全没有
        // 前置检查，H5 动作按钮 / 小程序的同步问答可越过配额无限消耗 provider 成本。
        try {
            $this->usageService->checkQuota('text');
            $this->usageService->checkBudget();
            $this->usageService->checkActorQuota('text');
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 402);
        }

        // 主体身份须在调用前捕获：UserAiRuntime::ask() 在 finally 里清空 ActorContext，
        // 而用后记账的主体级腿依赖它（BL-116）。
        $actorId = ActorContext::getId();
        $actorLevel = ActorContext::getLevel();

        $result = $this->runtime->ask(
            $validated['question'],
            $tenantId,
            $validated['visitor_key'] ?? null,
            context: new UserAiContext(accessLevel: $actorLevel, actorId: $actorId),
        );

        // 用后记账：把本轮真实消耗计入租户级 + 主体级配额（BL-116）
        $this->settleUsage($tenantId, $actorId, $actorLevel, $result['usage'] ?? null);

        return response()->json([
            'success' => true,
            'data' => [
                'allowed' => $result['allowed'],
                'answer' => $result['answer'],
                'sources' => $this->presentSources($result['sources']),
                'denied' => $result['denied'],
            ],
        ]);
    }

    /**
     * 用后记账（BL-116）：把本轮真实 token 消耗结算进租户级 + 主体级配额
     *
     * 与流式回调复用同一原子单元（{@see StreamUsageSettlementService}），但**不带
     * request_id**：同步请求没有客户端可重放的幂等键，服务端现造一个只能去重自己、
     * 徒增 settlement 表行数。故本路径原子但**不可幂等**（与结算服务的文档边界一致）
     * ——同步链路每次都是新的一次 LLM 生成，本就该各记一笔。
     *
     * 主体级腿依赖 ActorContext（`recordActorTextUsage` 未显式传 actorId 时回落读取），
     * 而 `UserAiRuntime::ask()` 的 finally 已清空上下文；这里按捕获的主体重设，用完
     * 即清，避免污染后续请求。
     *
     * 无用量时（未发生 LLM 调用 / provider 未回传 usage）不记账 —— 不伪造 0 消耗。
     *
     * 记账失败**不阻断响应**：回答已生成、成本已产生，把 5xx 抛给用户换不回任何东西
     * （且本路径不可幂等重试）。失败记 warning 供告警；该缺口只可能出现在数据库不可
     * 用的窗口内，而同一故障会让下一次请求的前置闸同样读不到配额——缺口自限。
     */
    private function settleUsage(int $tenantId, ?string $actorId, ?string $actorLevel, ?array $usage): void
    {
        if ($usage === null || $tenantId <= 0) {
            return;
        }

        $inputTokens = max(0, (int) ($usage['input_tokens'] ?? 0));
        $outputTokens = max(0, (int) ($usage['output_tokens'] ?? 0));

        if ($inputTokens + $outputTokens <= 0) {
            return;
        }

        try {
            ActorContext::set($actorId, $actorLevel ?? ActorContext::LEVEL_ANONYMOUS);

            $this->streamSettlement->settleStreamUsage(
                null,
                'user-ai-ask',
                $tenantId,
                $actorId !== null ? (int) $actorId : null,
                (string) ($usage['model'] ?? ''),
                $inputTokens,
                $outputTokens,
                ['source' => 'user-ai-ask'],
                true,
            );
        } catch (\Throwable $e) {
            Log::warning('[user-ai] ask usage settlement failed: ' . $e->getMessage(), [
                'tenant_id' => $tenantId,
                'actor_id' => $actorId,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
            ]);
        } finally {
            ActorContext::clear();
        }
    }

    /**
     * 呈现知识片段（对外只暴露必要字段，避免内部标识泄漏）
     */
    private function presentSources(array $sources): array
    {
        $presented = [];

        foreach (array_slice($sources, 0, 5) as $item) {
            if (! is_array($item)) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $presented[] = ['content' => mb_substr($text, 0, 500)];
                }

                continue;
            }

            $content = trim((string) ($item['content'] ?? $item['text'] ?? $item['title'] ?? ''));
            if ($content === '') {
                continue;
            }

            // 只透出内容与可选标题；不透出 provider 内部 id / 路径 / 元数据
            $row = ['content' => mb_substr($content, 0, 500)];

            $title = trim((string) ($item['title'] ?? ''));
            if ($title !== '') {
                $row['title'] = mb_substr($title, 0, 120);
            }

            $presented[] = $row;
        }

        return $presented;
    }
}
