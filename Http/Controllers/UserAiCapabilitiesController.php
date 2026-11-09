<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MultiTenantSaas\Context\TenantContext;
use MultiTenantSaas\Http\Controllers\BaseController;
use MultiTenantSaas\Modules\UserAi\Services\UserAiProfileSource;

/**
 * C 端 AI 能力面（UI 文案 + 工具运行标签）
 *
 * 租户画像（F2）绑定时：title/welcome/quick_questions/disclaimer 优先 profile.ui，
 * 工具标签优先 profile.toolLabels，工具集合按 profile.toolSurface 收窄（只减不增）。
 * 未绑定时与改造前逐项一致（BC）。
 */
class UserAiCapabilitiesController extends BaseController
{
    public function __construct(
        private readonly UserAiProfileSource $profiles,
    ) {}

    public function __invoke(): JsonResponse
    {
        $tenantId = (int) TenantContext::getId();
        $profile = $this->profiles->forTenant($tenantId);

        $ui = $this->profiles->ui($profile);
        $allowed = (array) config('user-ai.tool_surface.allowed', []);
        $labels = $this->profiles->toolLabels($profile);

        // 工具集合：白名单全集，绑定时按 profile 收窄。执行面同源（中间件把同一份
        // toolSurface 写进执行身份），因此这里不会展示「点了会被 surface_denied」的工具。
        $visible = $this->profiles->toolSurface($profile) ?? array_keys($allowed);

        $tools = [];

        foreach ($visible as $name) {
            if (! array_key_exists($name, $allowed)) {
                continue;
            }

            $label = $labels[$name] ?? null;

            if (! is_array($label)) {
                continue;
            }

            $tools[] = [
                'name' => (string) $name,
                'running' => (string) ($label['running'] ?? '正在处理'),
                'done' => (string) ($label['done'] ?? '处理完成'),
                'error' => (string) ($label['error'] ?? '处理失败'),
            ];
        }

        return response()->json([
            'success' => true,
            'data' => [
                'title' => (string) ($ui['title'] ?? '智能客服'),
                'welcome' => (string) ($ui['welcome'] ?? '你好，请问有什么可以帮你？'),
                'quick_questions' => array_values(array_filter(
                    (array) ($ui['quick_questions'] ?? []),
                    fn ($question) => is_string($question) && trim($question) !== '',
                )),
                'disclaimer' => (string) ($ui['disclaimer'] ?? ''),
                'input_max_chars' => max(1, (int) ($ui['input_max_chars'] ?? 2000)),
                'history_limit' => max(0, (int) config('user-ai.ask.max_history_turns', 6) * 2),
                'streaming' => (bool) config('ai-streaming.enabled', true),
                'requires_auth' => true,
                'tools' => $tools,
            ],
        ]);
    }
}
