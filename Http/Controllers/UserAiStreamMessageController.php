<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\UserAi\Services\UserAiConversationService;

/**
 * C 端流式消息回环落库（Node SSE 引擎回调，F4）
 *
 * `scope=user` 的流在结束时由 Node 调本端点上报 assistant 内容、工具结果与 usage；
 * operator 侧继续走 `/ai-streaming/messages/report`（承载 agent/conversation 语义），
 * 两条路径互不复用。
 *
 * 信任边界与其它 stream 契约端点同口径：`VerifyStreamingServiceToken` 服务凭据 +
 * `EnsureExternalStreamActor` 用户身份/成员/租户门。会话归属仍按服务端身份校验
 * （他人会话 404），不接受请求体 user_id。
 */
class UserAiStreamMessageController extends BaseController
{
    public function __construct(
        private readonly UserAiConversationService $conversations,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! config('ai-streaming.enabled', true)) {
            abort(503, 'AI 流式服务已关闭');
        }

        $data = $request->validate([
            'conversation_id' => ['required', 'integer', 'min:1'],
            'assistant_message' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'tool_calls' => ['sometimes', 'array'],
            'tool_results' => ['sometimes', 'array', 'max:20'],
            'tool_results.*.tool_call_id' => ['sometimes', 'nullable', 'string', 'max:100'],
            'tool_results.*.tool_name' => ['required', 'string', 'max:100'],
            'tool_results.*.content' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'sources' => ['sometimes', 'array', 'max:20'],
            'usage' => ['sometimes', 'array'],
        ]);

        $tenantId = (int) TenantContext::getId();
        $userId = (int) (ActorContext::getId() ?? 0);

        if ($tenantId <= 0 || $userId <= 0) {
            abort(401, '请先登录后使用智能问答');
        }

        $conversation = $this->conversations->findOwned($tenantId, $userId, (int) $data['conversation_id']);

        $message = $this->conversations->appendAssistant($conversation, [
            'content' => $data['assistant_message'] ?? null,
            'tool_calls' => $data['tool_calls'] ?? null,
            'sources' => $data['sources'] ?? null,
            'usage' => $data['usage'] ?? null,
            'tool_results' => $data['tool_results'] ?? null,
        ]);

        return response()->json([
            'success' => true,
            'data' => [
                'message_id' => (int) $message->message_id,
                'conversation_id' => (int) $conversation->conversation_id,
            ],
        ]);
    }
}
