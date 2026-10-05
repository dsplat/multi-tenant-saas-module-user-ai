<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| User AI（对外 AI 基座）配置
|--------------------------------------------------------------------------
|
| 工具面白名单是 fail-closed 的：未登记的工具对外部主体**不存在**
| （不进入 Function Calling 定义，执行侧咽喉也拒绝）。
|
| 键 = 工具 slug；值 = 允许触达该工具的**最低**身份等级
|   （anonymous / authenticated / verified）。
|
| 内部调用（Operator / 系统，未设置 ActorContext）不受本表约束。
|
*/

return [

    /*
    | 工具面白名单（暴露层）
    |
    | 默认只开放知识检索——匿名可问通用政策类问题。
    | 个人信息类工具（课表/成绩/缴费）必须等身份提升（verified）后才登记。
    |
    | 刻意不登记：conversation_tag（AI 会话打标）—— 外部主体不该改会话的
    | 质检标签；该工具供内部 Agent / 系统任务经 ToolRegistry 调用。
    |
    */
    'tool_surface' => [
        'allowed' => [
            'knowledge_search' => 'anonymous',
        ],
    ],

    /*
    | 出站内容守护
    |
    | AI 生成的回复在返回给外部用户前的最后一道闸：
    | 拦截内部标识（路由/类名/字段名）、调用栈痕迹、疑似凭据等。
    |
    | 失败语义：守护自身异常时记录告警并**放行**（与入站 ContentGuardService
    | 一致，遵循 AI 可选性铁律）；命中规则时**拦截**并返回安全兜底文案。
    |
    */
    'outbound_guard' => [
        'enabled' => (bool) env('USER_AI_OUTBOUND_GUARD_ENABLED', true),
        // 追加拦截关键词（归一化后精确包含匹配），逗号分隔
        'keywords' => array_filter(array_map('trim', explode(',', (string) env('USER_AI_OUTBOUND_KEYWORDS', '')))),
        // 拦截时返回的兜底文案
        'fallback_message' => '抱歉，这个问题我暂时无法回答，请稍后再试或联系工作人员。',
    ],

    /*
    | 问答（ask）行为
    |
    */
    'ask' => [
        // RAG 检索返回的最大条数
        'max_results' => (int) env('USER_AI_ASK_MAX_RESULTS', 5),
        // 是否用 LLM 基于检索结果合成回答；关闭则直接返回检索片段
        'synthesize' => (bool) env('USER_AI_ASK_SYNTHESIZE', true),
        // 合成回答时的模型温度（对外场景宜低，减少发挥）
        'temperature' => (float) env('USER_AI_ASK_TEMPERATURE', 0.3),
        // 检索无结果时的兜底文案
        'empty_answer' => '抱歉，知识库里暂时没有找到相关内容。如需进一步帮助，请联系工作人员。',

        /*
        | 多轮会话历史
        |
        | 外部主体（学生）连续追问时，调用方把前几轮对话回传进来，用于让模型
        | 知道上下文。历史上限是**成本与提示词可控**的双重约束：历史越长，
        | 每次追问的 token 越贵，越久的内容对当前问题也越无用。
        |
        | 历史被当作**不可信输入**处理：非法项一律跳过（见 UserAiRuntime::normalizeHistory）。
        |
        */
        // 最多带入的历史条数（1 条 = 一轮消息；超出则从最旧的开始丢弃）
        'max_history_turns' => (int) env('USER_AI_ASK_MAX_HISTORY_TURNS', 6),
        // 单条历史内容的最大字符数（超出截断，防止一条长文挤掉本轮资料）
        'max_history_chars' => (int) env('USER_AI_ASK_MAX_HISTORY_CHARS', 500),
    ],

    /*
    | 限流（公开接口，防刷）
    |
    */
    'throttle' => [
        // 每分钟每 IP 允许的问答次数
        'per_minute' => (int) env('USER_AI_THROTTLE_PER_MINUTE', 20),
    ],

    /*
    | 主体级配额（User 端外部主体，BL-040）
    |
    | 与租户级配额并存、取严：租户总闸约束「这个租户整体能用多少」，主体级
    | 约束「单个外部主体（User）能用多少」，防单主体刷爆租户额度。单位是
    | **Token 计数**（非金额）。0 = 不限 = 主体级闸关闭（默认，保证既有部署
    | 无回归）；设为正数即开启硬闸，超额一律拒绝（不受租户 overage_action 影响）。
    |
    */
    'quota' => [
        'subject_text_token_limit' => (int) env('USER_AI_SUBJECT_TOKEN_LIMIT', 0),
    ],

    /*
    | 流式模型配置（BL-030e 外部真·流式）
    |
    | C 端流式链路独立取模型档位，不复用 operator secretary 配置，避免耦合
    | 运营者账单口径。base_url / api_key 由 provider 名从 config('ai.providers.*')
    | 解析（见 UserAiStreamResolveController）。
    |
    */
    'model' => [
        'provider' => env('USER_AI_STREAM_PROVIDER', 'bailian'),
        'model' => env('USER_AI_STREAM_MODEL', 'qwen3.7-flash'),
        'temperature' => (float) env('USER_AI_STREAM_TEMPERATURE', 0.3),
        'max_tokens' => (int) env('USER_AI_STREAM_MAX_TOKENS', 2000),
    ],

    /*
    | 流式行为
    |
    */
    'stream' => [
        // 单轮流内工具调用上限（C 端只放 knowledge_search，取小值防循环烧钱）
        'max_tool_calls' => (int) env('USER_AI_STREAM_MAX_TOOL_CALLS', 2),
    ],

];
