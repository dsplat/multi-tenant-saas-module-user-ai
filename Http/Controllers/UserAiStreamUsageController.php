<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ExecutionAuditContract;
use MultiTenantSaas\Contracts\UsageSettlementContract;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\Ai\Services\StreamUsageSettlementService;
use MultiTenantSaas\Modules\Ai\Support\ExecutionAuditEvent;
use MultiTenantSaas\Modules\Ai\Support\ExecutionStatus;

/**
 * User 端 AI 流式用量结算（Node SSE 引擎回调）
 *
 * 与 operator 侧 UsageReportController 同用 {@see AiUsageService} 按当前租户记
 * used_tokens（TenantContext 由 `EnsureExternalStreamActor` 从 X-Tenant-ID 写入），
 * 差别是 C 端**无 Agent 归属**：不校验 agent_id、不写 operator 的 ai_requests 归属，
 * metadata.source 标记 'user-ai-stream' 以便与运营者流式账单区分口径。
 *
 * 此外叠加**主体级配额**结算：同一笔在租户级记账之外，按 ActorContext 的主体
 * （`EnsureExternalStreamActor` 由外部鉴权写入的 User）累加主体用量，供 resolve
 * 前置闸判超额。结算幂等由 settlement 统一兜底 —— already_settled 时两级都不重复计。
 *
 * 原子性（W8 / R9 / BL-102）：settle 标记、租户配额、主体配额三步由
 * {@see StreamUsageSettlementService} 的**同一个外层事务**提交；任一步失败整笔回滚，
 * 原 request_id 可重试（不会出现「标记已提交、配额未记账」的永久漏记）。审计旁路在
 * 事务提交后写入，审计失败不撤销已提交账本。
 */
class UserAiStreamUsageController extends BaseController
{
    /**
     * 结算原子单元（settle + 租户配额 + User 主体配额同一事务提交）。
     *
     * 未显式注入时由本控制器用自身已注入的 AiUsageService / UsageSettlementContract
     * 组装 —— 保证直接构造控制器（如既有单测）仍走同一套协作者，容器解析则自动注入。
     */
    private readonly StreamUsageSettlementService $streamSettlement;

    public function __construct(
        private readonly AiUsageService $usageService,
        private readonly UsageSettlementContract $settlement,
        private readonly ExecutionAuditContract $audit,
        ?StreamUsageSettlementService $streamSettlement = null,
    ) {
        $this->streamSettlement = $streamSettlement
            ?? new StreamUsageSettlementService($this->usageService, $this->settlement);
    }

    public function __invoke(Request $request): JsonResponse
    {
        if (! config('ai-streaming.enabled', true)) {
            abort(503, 'AI 流式服务已关闭');
        }

        $data = $request->validate([
            'model' => ['required', 'string', 'max:100'],
            'input_tokens' => ['required', 'integer', 'min:0'],
            'output_tokens' => ['required', 'integer', 'min:0'],
            'request_id' => ['sometimes', 'string', 'max:32'],
            'metadata' => ['sometimes', 'array'],
        ]);

        // 租户门控（与 resolve 同闸）：TenantContext 已由中间件按 X-Tenant-ID 设置
        $tenantId = (int) TenantContext::getId();
        if ($tenantId <= 0) {
            abort(403, '无法识别当前团队');
        }

        $metadata = (array) ($data['metadata'] ?? []);
        $metadata['source'] = 'user-ai-stream';
        $metadata['request_id'] = $data['request_id'] ?? null;

        // 原子单元：settle 标记 + 租户配额 + 主体配额同一事务提交。带 request_id 时严格
        // 幂等（already_settled 两级都不重复计）；缺 request_id 时原子但**不可去重**。
        $result = $this->streamSettlement->settleStreamUsage(
            ! empty($data['request_id']) ? (string) $data['request_id'] : null,
            'user-ai-stream',
            $tenantId,
            null,
            $data['model'],
            (int) $data['input_tokens'],
            (int) $data['output_tokens'],
            $metadata,
            true,
        );

        $status = $result['status'];
        if ($status === 'settlement_conflict') {
            abort(409, '请求结算内容冲突');
        }

        $quota = $result['quota'];

        // 审计旁路在事务提交之后写入：审计失败不会撤销已提交的账本。
        if (! empty($data['request_id'])) {
            $this->audit->record(new ExecutionAuditEvent(
                requestId: (string) $data['request_id'], scope: 'user', tenantId: $tenantId, actorId: null,
                tool: null, status: ExecutionStatus::SUCCESS, reasonCode: null,
                usage: ['input_tokens' => (int) $data['input_tokens'], 'output_tokens' => (int) $data['output_tokens']],
                provenance: ['source' => 'user-ai-stream', 'settlement' => $status],
            ));
        }

        return response()->json([
            'success' => true,
            'data' => [
                'recorded' => true,
                'tokens_used' => (int) $data['input_tokens'] + (int) $data['output_tokens'],
                'quota_used' => (int) $quota->used_tokens,
            ],
        ]);
    }
}
