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
 * C 端 AI 会话列表 / 详情 / 删除（F4）
 *
 * 全部路由挂 `EnsureExternalActor`：租户由中间件解析，主体只取服务端装载的
 * ExecutionIdentity（ActorContext 投影），**不接受请求体 user_id**。
 * 他人会话与不存在会话统一 404，避免探测。
 */
class UserAiConversationController extends BaseController
{
    public function __construct(
        private readonly UserAiConversationService $conversations,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        [$tenantId, $userId] = $this->identity();

        $page = $this->conversations->listFor($tenantId, $userId, (int) ($validated['per_page'] ?? 20));

        return response()->json([
            'success' => true,
            'data' => [
                'items' => array_map(static fn ($conversation): array => [
                    'conversation_id' => (int) $conversation->conversation_id,
                    'title' => $conversation->title,
                    'source' => (string) $conversation->source,
                    'message_count' => (int) $conversation->message_count,
                    'last_message_at' => $conversation->last_message_at?->toDateTimeString(),
                    'created_at' => $conversation->created_at?->toDateTimeString(),
                ], $page->items()),
                'total' => $page->total(),
                'page' => $page->currentPage(),
                'per_page' => $page->perPage(),
                'last_page' => $page->lastPage(),
            ],
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        [$tenantId, $userId] = $this->identity();

        $conversation = $this->conversations->findOwned($tenantId, $userId, $id);

        return response()->json([
            'success' => true,
            'data' => [
                'conversation' => [
                    'conversation_id' => (int) $conversation->conversation_id,
                    'title' => $conversation->title,
                    'source' => (string) $conversation->source,
                    'message_count' => (int) $conversation->message_count,
                    'last_message_at' => $conversation->last_message_at?->toDateTimeString(),
                    'created_at' => $conversation->created_at?->toDateTimeString(),
                ],
                'messages' => $this->conversations->messagesFor($conversation),
            ],
        ]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        [$tenantId, $userId] = $this->identity();

        $conversation = $this->conversations->findOwned($tenantId, $userId, $id);
        $this->conversations->forget($conversation);

        return response()->json([
            'success' => true,
            'data' => ['deleted' => true, 'conversation_id' => (int) $id],
        ]);
    }

    /**
     * 服务端身份：租户来自入口中间件，主体来自已装载执行身份
     *
     * @return array{0: int, 1: int}
     */
    private function identity(): array
    {
        $tenantId = (int) TenantContext::getId();
        $userId = (int) (ActorContext::getId() ?? 0);

        if ($tenantId <= 0 || $userId <= 0) {
            abort(401, '请先登录后使用智能问答');
        }

        return [$tenantId, $userId];
    }
}
