<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\Ai\Services\AiUsageService;

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
    public function __construct(private readonly AiUsageService $usageService) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! config('ai-streaming.enabled', true)) {
            abort(503, 'AI 流式服务已关闭');
        }

        $data = $request->validate([
            'model' => ['required', 'string', 'max:100'],
            'input_tokens' => ['required', 'integer', 'min:0'],
            'output_tokens' => ['required', 'integer', 'min:0'],
            'metadata' => ['sometimes', 'array'],
        ]);

        // 租户门控（与 resolve 同闸）：TenantContext 已由中间件按 X-Tenant-ID 设置
        $tenantId = (int) TenantContext::getId();
        if ($tenantId <= 0) {
            abort(403, '无法识别当前团队');
        }

        $metadata = (array) ($data['metadata'] ?? []);
        $metadata['source'] = 'user-ai-stream';

        $quota = $this->usageService->recordTextUsage(
            $data['model'],
            (int) $data['input_tokens'],
            (int) $data['output_tokens'],
            $metadata,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'recorded' => true,
                'tokens_used' => (int) $data['input_tokens'] + (int) $data['output_tokens'],
                'quota_used' => $quota->tokens_used ?? null,
            ],
        ]);
    }
}
