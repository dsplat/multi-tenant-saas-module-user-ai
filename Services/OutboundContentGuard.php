<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Services;

use Illuminate\Support\Facades\Log;

/**
 * 出站内容守护（User 端 AI 最后一道闸）
 *
 * AI 生成的回复在返回给外部用户之前必须过这一关。与入站的
 * ContentGuardService（src/Modules/Ai/Services/Ai/ContentGuardService.php）
 * 成对，但语义不同：
 *
 * - 入站守护拦「不该被处理的请求」（破坏性指令、SQL/代码执行诱导）
 * - 出站守护拦「不该被外部看到的回复」（内部标识、调用栈、疑似凭据）
 *
 * 失败语义（与入站**不同**——出站是外部可见内容的最后一道闸，无下游兜底）：
 * - 命中规则 → 拦截，返回兜底文案（不让外部用户看到风险内容）
 * - 守护自身异常 → 记告警并回**固定安全文案**（fail-closed，绝不放行原文）
 *
 * 入站守护（ContentGuardService）失败时 fail-open 是安全的：后面还有 L2 确认卡片、
 * 工具执行咽喉、配额等多道闸；而此处再往后就直接交给外部用户了，
 * 放行一份「守护没来得及检查」的原文 = 可能把内部标识 / 凭据泄出去。
 * 故出站守护自身异常时选择 fail-closed，用「暂时无法回答」换掉不可判定的内容。
 *
 * 不拦截业务词：答案里出现「课表 / 成绩 / 缴费」这类业务名词属正常，
 * 规则只针对**内部实现标识**与**敏感信息形态**。
 */
class OutboundContentGuard
{
    /**
     * 内置拦截规则
     *
     * 注意：normalize() 会做「全角转半角 + 去空白 + 转小写」，因此**所有模式
     * 必须按归一化后的形态书写**（小写、无空白）。与入站 ContentGuardService
     * 的约定一致——那里也是小写模式。
     */
    private const BUILTIN_PATTERNS = [
        // ── 内部路径 / 源码位置（归一化后仍保留 / 与 . 等符号） ──
        // 不要求尾部斜杠：`src/Modules` 单独出现已是泄漏
        'internal_path' => [
            '/src\/modules/',
            '/vendor\//',
            '/\/api\/v[0-9]+/',
            '/database\/migrations/',
            '/phpunit/',
        ],
        // ── 内部类名 / 方法名 / 系统字段（归一化后全小写、无空白） ──
        'internal_identifier' => [
            '/toolregistry/',
            '/isenabledfortenant/',
            '/iscapabilityregistered/',
            '/tenantcontext/',
            '/actorcontext/',
            '/userairuntime/',
            '/tenant_id/',
            '/agent_id/',
            '/operator_id/',
        ],
        // ── 调用栈 / 异常细节（归一化后空白已去除） ──
        'stack_trace' => [
            '/stacktrace/',
            '/#[0-9]+[a-z_\\\\]+->/',
            '/in\/[a-z0-9\/._-]+\.phponline[0-9]+/',
        ],
        // ── 疑似凭据 ──
        'credential_like' => [
            '/(api[_-]?key|secret|password|token|access[_-]?key)["\']?[:=]["\']?[a-z0-9\/+=_-]{8,}/',
            '/-----begin[a-z]*privatekey-----/',
        ],
    ];

    public function check(string $content): array
    {
        if (! (bool) config('user-ai.outbound_guard.enabled', true)) {
            return ['allowed' => true, 'category' => null, 'message' => null];
        }

        $text = trim($content);
        if ($text === '') {
            return ['allowed' => true, 'category' => null, 'message' => null];
        }

        try {
            $normalized = $this->normalize($text);
            $category = $this->match($normalized);

            if ($category !== null) {
                $this->recordBlock($category, $text);

                return [
                    'allowed' => false,
                    'category' => $category,
                    'message' => (string) config(
                        'user-ai.outbound_guard.fallback_message',
                        '抱歉，这个问题我暂时无法回答，请稍后再试或联系工作人员。'
                    ),
                ];
            }

            return ['allowed' => true, 'category' => null, 'message' => null];
        } catch (\Throwable $e) {
            // 出站铁律：这是外部可见内容的最后一道闸，没有下游兜底——
            // fail-open 会把「守护没来得及检查」的原文原样泄给外部用户，
            // 故 fail-closed：回固定安全文案，绝不返回原文（不因守护故障炸断链路，
            // 仍返回 200 与可展示文案，只是内容换成安全兜底）。
            $this->recordGuardFailure($e);

            return [
                'allowed' => false,
                'category' => 'guard_error',
                'message' => (string) config(
                    'user-ai.outbound_guard.fallback_message',
                    '抱歉，这个问题我暂时无法回答，请稍后再试或联系工作人员。'
                ),
            ];
        }
    }

    /**
     * 归一化：全角转半角 + 去零宽字符 + 去空白 + 转小写
     *
     * 三层处理各有针对的绕过手法（均为实测发现）：
     * - `mb_convert_kana($text, 'as')` 只转**字母数字与空格**，不转标点；
     *   而全角标点（／ ． ： － ＿ 等）能隔断普通正则，故补一张标点映射表
     * - 零宽字符（U+200B 等）不是 `\s`，会卡在标识符中间隔断匹配，须显式剥离
     * - 去空白 + 转小写让 `Tool Registry` / `toolregistry` / `Tool\tRegistry` 归一
     *
     * 已知局限（正则无法覆盖，属设计边界，见 docs/user-ai-design.md §7.2）：
     * 在标识符中间插入中文夹注（`Tool（注册表）Registry`）或同义改写仍可绕过。
     * 本守护是**形态过滤**，不是语义过滤；语义级泄漏（甲看到乙的数据）
     * 靠数据层的主体隔离，不靠这里。
     */
    public function normalize(string $text): string
    {
        $converted = mb_convert_kana($text, 'as', 'UTF-8');

        // 全角标点 → 半角（'a' 不处理标点，缺这段会被 ／ ． ： 等隔断）
        $converted = strtr($converted, [
            '／' => '/', '＼' => '\\', '．' => '.', '：' => ':', '－' => '-',
            '＿' => '_', '（' => '(', '）' => ')', '［' => '[', '］' => ']',
            '｜' => '|', '＝' => '=', '＋' => '+', '＠' => '@', '＃' => '#',
            '＜' => '<', '＞' => '>', '％' => '%', '＆' => '&', '＊' => '*',
            '！' => '!', '？' => '?', '，' => ',', '；' => ';', '　' => ' ',
        ]);

        // 剥离零宽 / 不可见字符（防 Tool{ZWSP}Registry 绕过）
        $stripped = preg_replace(
            '/[\x{200B}-\x{200F}\x{2028}-\x{202F}\x{2060}-\x{206F}\x{FEFF}]/u',
            '',
            $converted
        ) ?? $converted;

        $noSpace = preg_replace('/\s+/u', '', $stripped) ?? $stripped;

        return mb_strtolower($noSpace, 'UTF-8');
    }

    /**
     * 规则匹配：内置规则 + 配置追加关键词
     */
    private function match(string $normalized): ?string
    {
        foreach (self::BUILTIN_PATTERNS as $category => $patterns) {
            foreach ($patterns as $pattern) {
                if (@preg_match($pattern, $normalized) === 1) {
                    return $category;
                }
            }
        }

        $extraKeywords = (array) config('user-ai.outbound_guard.keywords', []);
        foreach ($extraKeywords as $keyword) {
            $keyword = $this->normalize((string) $keyword);
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                return 'custom_keyword';
            }
        }

        return null;
    }

    /**
     * 拦截审计（失败静默不影响响应）
     *
     * **不落原文**：命中的内容恰恰可能含疑似凭据 / 内部路径（正是被拦的对象），
     * 把原文摘录写进日志 = 把敏感内容从「外部回复」搬进「日志库」，换个地方泄漏。
     * 只留不可逆指纹（sha256 前 16 位）+ 长度，供运维按同一内容关联排查。
     */
    private function recordBlock(string $category, string $content): void
    {
        rescue(function () use ($category, $content) {
            Log::warning('[user-ai-outbound-guard] blocked', [
                'category' => $category,
                'content_hash' => substr(hash('sha256', $content), 0, 16),
                'content_length' => mb_strlen($content),
            ]);
        }, report: false);
    }

    /**
     * 守护自身异常告警（同样不落原文；异常信息与用户内容无关，可留以便定位）
     */
    private function recordGuardFailure(\Throwable $e): void
    {
        rescue(function () use ($e) {
            Log::warning('[user-ai-outbound-guard] check failed, fail-closed: ' . $e->getMessage());
        }, report: false);
    }
}
