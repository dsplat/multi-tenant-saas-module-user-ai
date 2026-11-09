<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use MultiTenantSaas\Modules\UserAi\Models\UserAiConversation;
use MultiTenantSaas\Modules\UserAi\Models\UserAiMessage;
use MultiTenantSaas\Scopes\TenantScope;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * C 端 AI 会话服务（F4）
 *
 * 服务端持久化的唯一入口：会话归属（tenant_id + user_id）与消息内容全部由本类写入，
 * 控制器只做身份取用与参数校验。**不接受请求体里的 user_id**。
 *
 * 归属校验统一 404（而非 403）：越权探测他人 conversation_id 时，不应区分
 * 「不存在」与「不属于你」。
 */
class UserAiConversationService
{
    /** 标题取首条用户消息的前 N 个字符（服务端生成） */
    private const TITLE_MAX_CHARS = 30;

    /** 单页会话上限（防大页拖库） */
    private const MAX_PER_PAGE = 100;

    /**
     * 续接或新建会话
     *
     * 传了 id：按 tenant_id + user_id + conversation_id 查，查不到 404；
     * 未传：新建。
     */
    public function resolve(
        int $tenantId,
        int $userId,
        ?int $conversationId,
        string $source = UserAiConversation::SOURCE_H5,
    ): UserAiConversation {
        if ($conversationId !== null) {
            return $this->findOwned($tenantId, $userId, $conversationId);
        }

        if (! in_array($source, UserAiConversation::SOURCES, true)) {
            $source = UserAiConversation::SOURCE_H5;
        }

        return UserAiConversation::create([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'source' => $source,
        ]);
    }

    /**
     * 取本人会话；他人 / 不存在一律 404
     */
    public function findOwned(int $tenantId, int $userId, int $conversationId): UserAiConversation
    {
        $conversation = UserAiConversation::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->where('conversation_id', $conversationId)
            ->first();

        if (! $conversation instanceof UserAiConversation) {
            throw new NotFoundHttpException('会话不存在');
        }

        return $conversation;
    }

    /**
     * 追加本轮用户消息，并在首条消息时生成会话标题
     */
    public function appendUserMessage(UserAiConversation $conversation, string $content): UserAiMessage
    {
        $content = trim($content);

        $message = $this->append($conversation, UserAiMessage::ROLE_USER, $content);

        if (trim((string) $conversation->title) === '') {
            $conversation->forceFill(['title' => $this->titleFrom($content)])->save();
        }

        return $message;
    }

    /**
     * 追加 assistant 回复（Node 流结束时上报）
     *
     * @param  array{content?: string|null, tool_calls?: array|null, sources?: array|null, usage?: array|null, tool_results?: array<int, array<string, mixed>>|null}  $payload
     */
    public function appendAssistant(UserAiConversation $conversation, array $payload): UserAiMessage
    {
        $content = trim((string) ($payload['content'] ?? ''));

        $message = $this->append($conversation, UserAiMessage::ROLE_ASSISTANT, $content !== '' ? $content : null, [
            'tool_calls' => $payload['tool_calls'] ?? null,
            'sources' => $payload['sources'] ?? null,
            'usage' => $payload['usage'] ?? null,
        ]);

        foreach ((array) ($payload['tool_results'] ?? []) as $toolResult) {
            if (! is_array($toolResult)) {
                continue;
            }

            $toolName = trim((string) ($toolResult['tool_name'] ?? ''));
            if ($toolName === '') {
                continue;
            }

            $toolContent = $toolResult['content'] ?? null;

            $this->append(
                $conversation,
                UserAiMessage::ROLE_TOOL,
                is_string($toolContent) ? $toolContent : (is_array($toolContent) ? (string) json_encode($toolContent, JSON_UNESCAPED_UNICODE) : null),
                ['tool_name' => mb_substr($toolName, 0, 100)],
            );
        }

        return $message;
    }

    /**
     * 服务端历史（最近的 N 轮，按时间升序）
     *
     * 只回放 user / assistant：tool 消息缺少与 assistant tool_calls 的成对结构，
     * 单独喂给模型会被当作非法上下文。
     *
     * @return array<int, array{role: string, content: string}>
     */
    public function history(UserAiConversation $conversation, ?int $turns = null): array
    {
        $turns ??= $this->configuredHistoryTurns();

        if ($turns <= 0) {
            return [];
        }

        return UserAiMessage::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->conversation_id)
            ->whereIn('role', [UserAiMessage::ROLE_USER, UserAiMessage::ROLE_ASSISTANT])
            ->orderByDesc('sequence')
            ->limit($turns * 2)
            ->get()
            ->reverse()
            // 空内容消息（纯工具轮的 assistant 占位）不进提示词：空 content 会让
            // 模型上下文出现无意义的空轮次
            ->filter(static fn (UserAiMessage $message): bool => trim((string) $message->content) !== '')
            ->values()
            ->map(static fn (UserAiMessage $message): array => [
                'role' => (string) $message->role,
                'content' => (string) $message->content,
            ])
            ->all();
    }

    /**
     * 会话消息（按 sequence 升序 = 写入顺序）
     *
     * @return array<int, array<string, mixed>>
     */
    public function messagesFor(UserAiConversation $conversation): array
    {
        return UserAiMessage::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->conversation_id)
            ->orderBy('sequence')
            ->get()
            ->map(static fn (UserAiMessage $message): array => [
                'message_id' => (int) $message->message_id,
                'role' => (string) $message->role,
                'content' => $message->content,
                'tool_name' => $message->tool_name,
                'tool_calls' => $message->tool_calls,
                'sources' => $message->sources,
                'usage' => $message->usage,
                'created_at' => $message->created_at?->toDateTimeString(),
            ])
            ->all();
    }

    /**
     * 本人会话列表（last_message_at 降序 + conversation_id 唯一键收尾）
     */
    public function listFor(int $tenantId, int $userId, int $perPage = 20): LengthAwarePaginator
    {
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));

        return UserAiConversation::query()
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->orderByDesc('last_message_at')
            ->orderByDesc('conversation_id')
            ->paginate($perPage);
    }

    /**
     * 删除本人会话（消息 + 会话；删除后不可再读）
     */
    public function forget(UserAiConversation $conversation): void
    {
        $this->deleteWithMessages($conversation);
    }

    /**
     * 清理超过保留期的会话（0 = 不清理）
     *
     * 控制台无租户上下文，TenantScope 默认 fail-closed（WHERE 1=0），故此处显式
     * 声明系统级豁免；逐会话删除时仍显式带 tenant_id，不依赖上下文补全。
     *
     * @return int 清理的会话数
     */
    public function prune(int $days): int
    {
        if ($days <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($days);
        $pruned = 0;

        TenantScope::allowUnscoped(function () use ($cutoff, &$pruned): void {
            UserAiConversation::query()
                ->where(static function ($query) use ($cutoff): void {
                    $query->where('last_message_at', '<', $cutoff)
                        ->orWhere(static function ($inner) use ($cutoff): void {
                            $inner->whereNull('last_message_at')->where('created_at', '<', $cutoff);
                        });
                })
                ->orderBy('conversation_id')
                ->chunkById(100, function ($conversations) use (&$pruned): void {
                    foreach ($conversations as $conversation) {
                        $this->deleteWithMessages($conversation);
                        $pruned++;
                    }
                }, 'conversation_id');
        });

        return $pruned;
    }

    private function append(
        UserAiConversation $conversation,
        string $role,
        ?string $content,
        array $extra = [],
    ): UserAiMessage {
        $message = UserAiMessage::create([
            'tenant_id' => $conversation->tenant_id,
            'conversation_id' => $conversation->conversation_id,
            'role' => $role,
            'content' => $content,
            ...array_filter($extra, static fn ($value): bool => $value !== null),
        ]);

        $conversation->forceFill([
            'message_count' => (int) $conversation->message_count + 1,
            'last_message_at' => $message->created_at ?? now(),
        ])->save();

        return $message;
    }

    private function deleteWithMessages(UserAiConversation $conversation): void
    {
        UserAiMessage::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->conversation_id)
            ->delete();

        $conversation->delete();
    }

    private function titleFrom(string $content): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $content));

        return mb_substr($normalized, 0, self::TITLE_MAX_CHARS);
    }

    private function configuredHistoryTurns(): int
    {
        $value = config('user-ai.conversation.history_turns', 6);

        return is_numeric($value) ? max(0, (int) $value) : 6;
    }
}
