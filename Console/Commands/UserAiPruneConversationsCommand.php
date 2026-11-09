<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Console\Commands;

use Illuminate\Console\Command;
use MultiTenantSaas\Modules\UserAi\Services\UserAiConversationService;

/**
 * C 端 AI 会话留存清理（F4）
 *
 * 默认读 `config('user-ai.conversation.retention_days')`（默认 90；0 = 不清理）。
 * 建议随部署计划任务每日执行一次；不自动删除未过期会话。
 */
class UserAiPruneConversationsCommand extends Command
{
    protected $signature = 'user-ai:prune-conversations
                            {--days= : 保留天数（默认读 user-ai.conversation.retention_days；0 表示不清理）}';

    protected $description = '清理超过保留期的 C 端 AI 会话及其消息';

    public function handle(UserAiConversationService $conversations): int
    {
        $days = $this->option('days');

        if ($days === null) {
            $configured = config('user-ai.conversation.retention_days', 90);
            $days = is_numeric($configured) ? (int) $configured : 90;
        } else {
            $days = (int) $days;
        }

        if ($days <= 0) {
            $this->info('保留天数为 0（不清理），已跳过。');

            return self::SUCCESS;
        }

        $pruned = $conversations->prune($days);

        $this->info("已清理 {$pruned} 个超过 {$days} 天的 C 端 AI 会话。");

        return self::SUCCESS;
    }
}
