<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Dto;

/**
 * 一次对外问答的调用上下文
 *
 * 把「谁在问」与「用什么 AI 设置」收在一处：ask() 的参数曾随需求逐个追加
 * （history → accessLevel/actorId → 人设/模型档位），再往下加就会变成一长串
 * 位置相近的标量参数 —— 调用点很容易传错顺序，而传错 accessLevel 与 persona
 * 的后果完全不同（前者是越权，后者只是人设不对）。集中成一个具名对象后，
 * 新增字段不再改变方法签名。
 *
 * 全部字段都由**服务端**判定后传入，不接受请求体直接映射。
 */
final class UserAiContext
{
    /**
     * @param  string|null  $accessLevel  外部主体等级（ActorContext::LEVEL_*）；
     *                                    null 按匿名处理。**只能由服务端判定**
     * @param  string|null  $actorId  已识别的系统用户 ID（未关联则 null）
     * @param  string|null  $persona  人设/角色描述（来自客服 Agent 的系统提示词）。
     *                                属**附加**内容，不得替换掉固定的安全约束
     * @param  array<string, mixed>  $modelOptions  模型档位（model / provider / temperature / max_tokens）
     * @param  array<int, string>|null  $toolScope  该主体可用的工具**子集**（null = 不额外限制）。
     *                                              只能收窄，放行不了暴露面白名单之外的工具
     */
    public function __construct(
        public readonly ?string $accessLevel = null,
        public readonly ?string $actorId = null,
        public readonly ?string $persona = null,
        public readonly array $modelOptions = [],
        public readonly ?array $toolScope = null,
    ) {}

    public static function anonymous(): self
    {
        return new self;
    }
}
