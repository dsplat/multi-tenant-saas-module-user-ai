<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * C 端 AI 小秘书独立会话表（F4）
 *
 * 铁律：**不复用 `conversations`**（operator 渠道语义：channel / 接待态镜像 / created_by，
 * 客服看板按它统计）。C 端会话独立建表，主键为全局 ID，禁止自增 `id`。
 *
 * 迁移随发布包下发（modules 仓 split 后由 composer 升级带入），禁止改已发布文件。
 * sqlite / MySQL 同形：只用 Schema Builder，无裸 DDL、无 ENGINE/SET 语句。
 *
 * 排序约定：列表按 last_message_at 截断时必须唯一键（conversation_id）收尾；
 * 会话内消息按 (created_at, message_id) 升序，同样以唯一键收尾。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('user_ai_conversations')) {
            Schema::create('user_ai_conversations', function (Blueprint $table): void {
                $table->unsignedBigInteger('conversation_id')->primary()->comment('全局 ID（IdGenerator）');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('user_id');
                $table->string('source', 20)->default('h5')->comment('h5|miniapp');
                // 首条用户消息截断，服务端生成；不接受客户端传入
                $table->string('title', 100)->nullable();
                $table->unsignedInteger('message_count')->default(0);
                $table->timestamp('last_message_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->index(['tenant_id', 'user_id'], 'user_ai_conversations_tenant_user_index');
                $table->index(['tenant_id', 'last_message_at'], 'user_ai_conversations_tenant_last_index');
            });
        }

        if (! Schema::hasTable('user_ai_messages')) {
            Schema::create('user_ai_messages', function (Blueprint $table): void {
                $table->unsignedBigInteger('message_id')->primary()->comment('全局 ID（IdGenerator）');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('conversation_id');
                // 会话内单调序号（HasConversationSequence）：message_id 是随机全局 ID、
                // created_at 是秒精度，二者都无法承载「同一秒内多条消息」的顺序。
                $table->unsignedInteger('sequence')->comment('会话内单调序号（1 起）');
                $table->string('role', 20)->comment('user|assistant|tool');
                $table->text('content')->nullable();
                $table->string('tool_name', 100)->nullable();
                $table->json('tool_calls')->nullable();
                $table->json('sources')->nullable();
                $table->json('usage')->nullable();
                $table->timestamp('created_at')->nullable();
                $table->index(['tenant_id', 'conversation_id'], 'user_ai_messages_tenant_conversation_index');
                $table->unique(['conversation_id', 'sequence'], 'user_ai_messages_conversation_sequence_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ai_messages');
        Schema::dropIfExists('user_ai_conversations');
    }
};
