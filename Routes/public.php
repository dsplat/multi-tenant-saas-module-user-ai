<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\UserAi\Http\Controllers\UserAiController;
use MultiTenantSaas\Modules\UserAi\Http\Controllers\UserAiStreamResolveController;
use MultiTenantSaas\Modules\UserAi\Http\Controllers\UserAiStreamToolController;
use MultiTenantSaas\Modules\UserAi\Http\Controllers\UserAiStreamUsageController;
use MultiTenantSaas\Modules\UserAi\Http\Middleware\EnsureExternalActor;
use MultiTenantSaas\Modules\UserAi\Http\Middleware\EnsureExternalStreamActor;

/*
|--------------------------------------------------------------------------
| User AI Public Routes
|--------------------------------------------------------------------------
|
| 公开路由（入口闸内强制认证和有效租户成员校验），供外部主体（学生 / 家长 / 访客）提问。
|
| 基类 ModuleServiceProvider::loadModuleRoutes() 对 public.php 的处理：
|   前缀 api/v1，仅挂 `api` 中间件（无 auth / 无 tenant.identify）。
| 因此以下两项必须在此显式声明：
|
|   throttle:user-ai      —— 限流器在 UserAiServiceProvider::bootModule 注册，
|                            速率读 config('user-ai.throttle.per_minute')。
|                            公开 AI 接口有真实 LLM/RAG 成本，漏了等于无限制烧钱。
|   EnsureExternalActor   —— 外部主体入口闸：解析租户 + 租户级模块门控 +
|                            **设置 ActorContext**（暴露层闸门的前提）+ 结束清理。
|
| ⚠ 新增任何公开路由都必须带 EnsureExternalActor。忘记挂载会让该路由上的
|   工具调用绕过暴露层闸门（ActorContext 未设置 → 闸门跳过）。UserAiWiringTest
|   会对此断言并失败。
|
*/

Route::post('/user-ai/ask', [UserAiController::class, 'ask'])
    ->middleware(['throttle:user-ai', EnsureExternalActor::class]);

/*
|--------------------------------------------------------------------------
| User AI 流式契约（Node SSE 引擎回调）
|--------------------------------------------------------------------------
|
| BL-030e「外部真·流式」：H5 C 端浏览器打 Node `/ai-stream/chat`（scope=user），
| Node 再回调以下三个端点完成鉴权/护栏/记账——拓扑与 operator 流式一致，但 PHP
| 侧走 UserAi 而非 operator AiStreaming：
|
|   为什么不放 AiStreaming/Routes/api.php：那组继承 auth:sanctum（见基类
|   ModuleServiceProvider），C 端身份和成员归属在 ExternalTenantAccess 统一校验；公开契约端点必须落本模块
|   public.php（仅 `api` 中间件）。
|
| 三个端点均挂 EnsureExternalStreamActor：按 X-Tenant-ID 解析租户 + user-ai
|   模块门控 + 设置 ActorContext（暴露层闸门前提）+ 结束清理。
| 限流走 throttle:user-ai-stream：Node→PHP 是 127.0.0.1 回环，$request->ip()
|   恒为回环地址，故按 Node 透传的 X-Client-IP 计数（见 UserAiServiceProvider）。
|
*/
Route::post('/user-ai/stream/resolve', UserAiStreamResolveController::class)
    ->middleware(['throttle:user-ai-stream', EnsureExternalStreamActor::class]);

Route::post('/user-ai/stream/tools', UserAiStreamToolController::class)
    ->middleware(['throttle:user-ai-stream', EnsureExternalStreamActor::class]);

Route::post('/user-ai/stream/usage', UserAiStreamUsageController::class)
    ->middleware(['throttle:user-ai-stream', EnsureExternalStreamActor::class]);
