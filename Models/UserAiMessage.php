<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use MultiTenantSaas\Concerns\BelongsToTenant;
use MultiTenantSaas\Concerns\HasConversationSequence;
use MultiTenantSaas\Concerns\HasGlobalId;
use MultiTenantSaas\Concerns\SerializesFriendlyDates;

/**
 * C 端 AI 会话消息（F4）
 *
 * role: user | assistant | tool。assistant/tool 由 Node 流结束时的内部回调落库
 * （`POST /user-ai/stream/messages`），user 由 resolve 落库（Node 传来的最后一条用户消息）。
 *
 * 只有 created_at（消息追加后不可编辑），故 UPDATED_AT 置 null。
 *
 * 会话内顺序由 `sequence`（{@see HasConversationSequence}）承载：
 * message_id 是随机全局 ID、created_at 是秒精度，都无法区分「同一秒内
 * user → assistant → tool」这三条消息的先后。
 */
class UserAiMessage extends Model
{
    use BelongsToTenant, HasConversationSequence, HasGlobalId;
    use SerializesFriendlyDates;

    public const ROLE_USER = 'user';

    public const ROLE_ASSISTANT = 'assistant';

    public const ROLE_TOOL = 'tool';

    public const ROLES = [self::ROLE_USER, self::ROLE_ASSISTANT, self::ROLE_TOOL];

    /** 只追加，不更新 */
    public const UPDATED_AT = null;

    protected $table = 'user_ai_messages';

    protected $primaryKey = 'message_id';

    protected $fillable = [
        'tenant_id', 'conversation_id', 'role', 'content', 'tool_name',
        'tool_calls', 'sources', 'usage', 'created_at',
    ];

    protected function casts(): array
    {
        return [
            'tool_calls' => 'array',
            'sources' => 'array',
            'usage' => 'array',
            'created_at' => 'datetime',
            'sequence' => 'integer',
        ];
    }

    /**
     * 序号分配时锁的父表（默认锁 conversations，本表是 C 端独立会话）
     */
    protected function sequenceLockTable(): ?string
    {
        return 'user_ai_conversations';
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(UserAiConversation::class, 'conversation_id', 'conversation_id');
    }
}
