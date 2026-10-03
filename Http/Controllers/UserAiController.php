<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Modules\UserAi\Dto\UserAiContext;
use MultiTenantSaas\Modules\UserAi\Services\UserAiRuntime;

/**
 * User 端 AI 公开接口
 *
 * 边界职责（租户解析 / 模块门控 / 主体上下文）全部由 `EnsureExternalActor`
 * 中间件承担，控制器只做参数校验与呈现——见 Routes/public.php。
 */
class UserAiController extends Controller
{
    public function __construct(private readonly UserAiRuntime $runtime) {}

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

        $result = $this->runtime->ask(
            $validated['question'],
            $tenantId,
            $validated['visitor_key'] ?? null,
            context: new UserAiContext(accessLevel: ActorContext::getLevel(), actorId: ActorContext::getId()),
        );

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
