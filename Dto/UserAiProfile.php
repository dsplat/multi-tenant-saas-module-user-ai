<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Dto;

use MultiTenantSaas\Modules\UserAi\Contracts\UserAiProfileResolverContract;

/**
 * 租户级 C 端 AI 画像（{@see UserAiProfileResolverContract} 的返回值）
 *
 * 全部字段可空：null / 空数组 = 不覆盖，回落 config('user-ai.*')。
 */
final readonly class UserAiProfile
{
    /**
     * @param  array<int, string>|null  $toolSurface  允许下发的 slug；null = 不收窄
     * @param  array<string, mixed>  $ui  title/welcome/quick_questions/disclaimer
     * @param  array<string, array{running: string, done: string, error: string}>  $toolLabels
     */
    public function __construct(
        public ?string $persona = null,
        public ?array $toolSurface = null,
        public array $ui = [],
        public array $toolLabels = [],
    ) {}
}
