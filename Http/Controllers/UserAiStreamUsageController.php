<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ExecutionAuditContract;
use MultiTenantSaas\Contracts\UsageSettlementContract;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;
use MultiTenantSaas\Modules\Ai\Support\ExecutionAuditEvent;
use MultiTenantSaas\Modules\Ai\Support\ExecutionStatus;

/**
 * User 端 AI 流式用量结算（Node SSE 引擎回调）
 *
 * 与 operator 侧 UsageReportController 同用 {@see AiUsageService} 按当前租户记
 * used_tokens（TenantContext 由 `EnsureExternalStreamActor` 从 X-Tenant-ID 写入），
 * 差别是 C 端**无 Agent 归属**：不校验 agent_id、不写 operator 的 ai_requests 归属，
 * metadata.source 标记 'user-ai-stream' 以便与运营者流式账单区分口径。
 */
class UserAiStreamUsageController extends Controller
{
    public function __construct(
        private readonly AiUsageService $usageService,
        private readonly UsageSettlementContract $settlement,
        private readonly ExecutionAuditContract $audit,
    ) {}

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

        $status = 'settled';
        if (! empty($data['request_id'])) {
            $status = $this->settlement->settle(
                (string) $data['request_id'],
                'user-ai-stream',
                $tenantId,
                null,
                (int) $data['input_tokens'],
                (int) $data['output_tokens'],
            );
            if ($status === 'settlement_conflict') {
                abort(409, '请求结算内容冲突');
            }
        }

        $quota = $status === 'already_settled'
            ? $this->usageService->getOrCreateCurrentQuota()
            : $this->usageService->recordTextUsage(
                $data['model'],
                (int) $data['input_tokens'],
                (int) $data['output_tokens'],
                $metadata,
            );

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
