<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * C 端 AI 小秘书会话（F4）
 *
 * 与 operator 的 `conversations` 表**互不复用**：后者承载渠道语义（channel /
 * 接待态镜像 / created_by，客服看板按它统计），C 端会话只服务「某个租户内的
 * 某个前台用户与 AI 的多轮上下文」。
 *
 * 归属（tenant_id + user_id）只由服务端按 Bearer 身份写入，绝不接受请求体 user_id。
 */
class UserAiConversation extends Model
{
    use BelongsToTenant, HasGlobalId;
    use SerializesFriendlyDates;

    /** H5 网页端 */
    public const SOURCE_H5 = 'h5';

    /** 小程序端 */
    public const SOURCE_MINIAPP = 'miniapp';

    public const SOURCES = [self::SOURCE_H5, self::SOURCE_MINIAPP];

    protected $table = 'user_ai_conversations';

    protected $primaryKey = 'conversation_id';

    protected $fillable = [
        'tenant_id', 'user_id', 'source', 'title', 'message_count', 'last_message_at', 'metadata',
    ];

    protected function casts(): array
    {
        return [
            'message_count' => 'integer',
            'last_message_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(UserAiMessage::class, 'conversation_id', 'conversation_id')
            ->orderBy('sequence');
    }

    public function belongsToUser(int $tenantId, int $userId): bool
    {
        return (int) $this->tenant_id === $tenantId && (int) $this->user_id === $userId;
    }
}
