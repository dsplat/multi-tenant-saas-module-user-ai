<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Contracts;

use MultiTenantSaas\Modules\UserAi\Dto\UserAiProfile;

/**
 * 租户级 C 端 AI 画像：人格、工具面、UI 文案。
 *
 * 未绑定 / 返回 null / 字段为 null 时，一律回落 config('user-ai.*') 的现有行为。
 * toolSurface 只能**收窄** config('user-ai.tool_surface.allowed') 的集合，不能扩充；
 * 等级仍以 config 为准，profile 不参与等级判定。
 *
 * 下游（如 scrm Workbench）在自身 ServiceProvider 中绑定本契约即可生效。
 */
interface UserAiProfileResolverContract
{
    public function forTenant(int $tenantId): ?UserAiProfile;
}
