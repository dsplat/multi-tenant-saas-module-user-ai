<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Contracts\ToolRegistryContract;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\AiStreaming\Http\Controllers\ToolExecuteController;

/**
 * User 端 AI 流式工具执行（Node SSE 引擎回调）
 *
 * 与 operator {@see ToolExecuteController}
 * 的语义差异：C 端**没有 Agent 归属概念**，越权防线换成 tool_surface 白名单闸门。
 *
 * 两道防线（最严者胜）：
 *   ① 显式白名单校验：请求的 tool 不在 `config('user-ai.tool_surface.allowed')`
 *      内直接 403——即便 Node 被伪造上行任意 slug，也进不了执行咽喉。
 *   ② ToolRegistry::execute 暴露层闸门：由 `EnsureExternalStreamActor` 设置的
 *      ActorContext（anonymous）驱动，白名单外/身份不足/模块未开通在此二次拦截。
 *
 * operator 侧的 L2 确认门 / 选项卡互斥门等均为**写操作与运营会话**而生，
 * knowledge_search 只读、C 端 v1 无会话，天然不触发，故此处走最简 execute 直返。
 */
class UserAiStreamToolController extends BaseController
{
    public function __construct(private readonly ToolRegistryContract $toolRegistry) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! config('ai-streaming.enabled', true)) {
            abort(503, 'AI 流式服务已关闭');
        }

        $data = $request->validate([
            'tool' => ['required', 'string', 'max:100'],
            'arguments' => ['sometimes', 'array'],
        ]);

        $tenantId = (int) TenantContext::getId();

        // ① 显式白名单闸：C 端只认 tool_surface.allowed 内登记的工具
        $allowed = (array) config('user-ai.tool_surface.allowed', []);
        if (! array_key_exists($data['tool'], $allowed)) {
            return response()->json([
                'success' => false,
                'message' => "工具 [{$data['tool']}] 不在对外白名单内",
            ], 403);
        }

        // ② 执行咽喉（暴露层闸门 + 模块开通门控在此兜底）；
        //    knowledge_search 只读，无 L2/选项卡门，直接返回结果
        try {
            $result = $this->toolRegistry->execute(
                $data['tool'],
                (array) ($data['arguments'] ?? []),
                $tenantId,
            );
        } catch (\RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'data' => ['result' => $result],
        ]);
    }
}
