<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Http\Controllers;

use Illuminate\Http\JsonResponse;
use MultiTenantSaas\Http\Controllers\BaseController;

class UserAiCapabilitiesController extends BaseController
{
    public function __invoke(): JsonResponse
    {
        $ui = (array) config('user-ai.ui', []);
        $allowed = (array) config('user-ai.tool_surface.allowed', []);
        $labels = (array) config('user-ai.tool_surface.labels', []);
        $tools = [];

        foreach (array_intersect_key($labels, $allowed) as $name => $label) {
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
