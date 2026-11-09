<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Services;

use Illuminate\Support\Facades\Log;
use MultiTenantSaas\Modules\UserAi\Contracts\UserAiProfileResolverContract;
use MultiTenantSaas\Modules\UserAi\Dto\UserAiProfile;

/**
 * 可选画像解析（F2）
 *
 * 下游未绑定 {@see UserAiProfileResolverContract} 时等价于「无 profile」：
 * 所有取值回落 config('user-ai.*')，既有行为零变化（BC）。
 *
 * 解析失败按无 profile 处理（记 warning）：画像只是增强项，不能因为下游实现
 * 抛错就让 C 端问答整体不可用；安全边界（白名单 / 等级 / 主体）都不依赖它。
 */
class UserAiProfileSource
{
    /** 允许被 profile.ui 覆盖的键（其余 UI 配置仍以 config 为准） */
    private const UI_KEYS = ['title', 'welcome', 'quick_questions', 'disclaimer'];

    public function forTenant(int $tenantId): ?UserAiProfile
    {
        if ($tenantId <= 0 || ! app()->bound(UserAiProfileResolverContract::class)) {
            return null;
        }

        $resolver = app(UserAiProfileResolverContract::class);

        if (! $resolver instanceof UserAiProfileResolverContract) {
            return null;
        }

        try {
            return $resolver->forTenant($tenantId);
        } catch (\Throwable $exception) {
            Log::warning('[user-ai] profile resolver failed, falling back to config: ' . $exception->getMessage(), [
                'tenant_id' => $tenantId,
            ]);

            return null;
        }
    }

    /**
     * 收窄后的工具面：profile.toolSurface ∩ config('user-ai.tool_surface.allowed') 的键
     *
     * null = 不收窄（profile 未绑定 / 未声明）；空数组 = 该租户一个工具都不下发
     * （profile 声明的工具全部不在白名单内，只能收窄、不能扩充）。
     *
     * @return array<int, string>|null
     */
    public function toolSurface(?UserAiProfile $profile): ?array
    {
        if ($profile?->toolSurface === null) {
            return null;
        }

        $allowed = array_keys((array) config('user-ai.tool_surface.allowed', []));
        $declared = array_values(array_filter(
            $profile->toolSurface,
            static fn ($slug): bool => is_string($slug) && trim($slug) !== '',
        ));

        return array_values(array_intersect(array_map('trim', $declared), $allowed));
    }

    /**
     * UI 文案：profile.ui 优先，其次 config('user-ai.ui')
     *
     * 只接受 title / welcome / quick_questions / disclaimer 四个键；
     * 其余键（如 input_max_chars）属于平台行为约束，不接受租户覆盖。
     *
     * @return array<string, mixed>
     */
    public function ui(?UserAiProfile $profile): array
    {
        $ui = (array) config('user-ai.ui', []);

        if ($profile === null) {
            return $ui;
        }

        foreach (self::UI_KEYS as $key) {
            if (! array_key_exists($key, $profile->ui)) {
                continue;
            }

            $value = $profile->ui[$key];

            // 形态校验：下游实现出错时回落 config，而不是把数组塞进 title 之类的字段
            if ($key === 'quick_questions') {
                if (is_array($value)) {
                    $ui[$key] = $value;
                }

                continue;
            }

            if (is_string($value)) {
                $ui[$key] = $value;
            }
        }

        return $ui;
    }

    /**
     * 工具运行文案：profile.toolLabels 覆盖 config('user-ai.tool_surface.labels')
     *
     * 递归合并：下游只给 running 时，done/error 仍取 config 的文案。
     *
     * @return array<string, mixed>
     */
    public function toolLabels(?UserAiProfile $profile): array
    {
        $labels = (array) config('user-ai.tool_surface.labels', []);

        if ($profile === null || $profile->toolLabels === []) {
            return $labels;
        }

        $overrides = array_filter($profile->toolLabels, static fn ($label): bool => is_array($label));

        return $overrides === [] ? $labels : array_replace_recursive($labels, $overrides);
    }
}
