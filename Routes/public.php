<?php

use Illuminate\Support\Facades\Route;
use MultiTenantSaas\Modules\UserAi\Http\Controllers\UserAiController;
use MultiTenantSaas\Modules\UserAi\Http\Middleware\EnsureExternalActor;

/*
|--------------------------------------------------------------------------
| User AI Public Routes
|--------------------------------------------------------------------------
|
| 公开路由（无需认证），供外部主体（学生 / 家长 / 访客）提问。
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
