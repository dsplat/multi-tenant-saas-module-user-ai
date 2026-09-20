<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Tools;

use MultiTenantSaas\Context\ActorContext;
use MultiTenantSaas\Modules\Ai\Services\Agent\Contracts\ToolHandlerContract;

/**
 * 主体受限工具处理器（对外主体可见的「与我相关」类工具基类）
 *
 * 背景：对外问答里那些会返回**属于某个人**的数据的工具（课表 / 成绩 / 缴费 / 我的工单…），
 * 一旦忘了按主体过滤，就是一次批量泄漏 —— 而且是静默的：测试不覆盖就发现不了，
 * 直到线上有人查到了别人的成绩。
 *
 * 框架能保证的只有三件事，其余（具体查什么、怎么查）属业务：
 *   1. **主体 ID 只来自服务端身份**（ActorContext），绝不接受工具入参
 *      —— AI 生成的参数是不可信输入，若允许它传 user_id，模型被诱导就能查别人
 *   2. **身份未知就直接拒绝**：匿名主体没有可约束的行，不存在「查全部」这种行为
 *   3. **结果归属自检**：返回的每条记录都必须带归属列且等于主体 ID，
 *      否则拒绝整次调用 —— 把「忘了加 WHERE」从静默泄漏变成一次明确的拒绝
 *
 * 子类只需要实现 handleForSubject()，它拿到的 subjectId 由基类解析后传入，
 * 因此**结构上不可能**从入参拿到别人的 ID。
 *
 * 归属自检的边界：只检查**扁平结构**的行（行数组，或单行关联数组）。
 * 若工具返回的是包装结构（如 `['results' => [...]]`），
 * 要么在 handleForSubject 里先拍平成行列表再返回，要么把 subjectKey() 返回 null 显式
 * 声明「本工具不返回属于个人的行」—— 后者是唯一的退出方式，且必须是明确的、可审查的。
 */
abstract class SubjectScopedToolHandler implements ToolHandlerContract
{
    final public function __invoke(array $arguments, int $tenantId): mixed
    {
        $subjectId = $this->resolveSubjectId();

        if ($subjectId === null) {
            // 匿名主体：没有可约束的行。返回结构化拒绝而非异常 ——
            // 与执行咽喉的拒绝同构，调用方（AI 循环）按 error 分支处理
            return [
                'error' => true,
                'denied' => 'subject_unknown',
                'message' => '需要先确认身份才能查询与该账号相关的信息',
            ];
        }

        $result = $this->handleForSubject($subjectId, $arguments, $tenantId);

        return $this->verifyOwnership($result, $subjectId);
    }

    /**
     * 业务实现
     *
     * @param  int  $subjectId  **服务端解析**的主体 ID（用户 ID），不是入参里来的
     * @param  array<string, mixed>  $arguments  AI 生成的参数（不可信）
     */
    abstract protected function handleForSubject(int $subjectId, array $arguments, int $tenantId): mixed;

    /**
     * 返回行里的归属列名；返回 null 表示显式声明「不返回属于个人的行」
     *
     * 默认 `user_id`（框架里主体归属列的通行命名）。改成 null 前请确认：
     * 这个工具的输出里确实不存在任何属于具体个人的数据。
     */
    protected function subjectKey(): ?string
    {
        return 'user_id';
    }

    /**
     * 主体 ID 从服务端身份解析
     *
     * 只有已识别/已核身的主体才有 ID；匿名（ActorContext 未设 id）返回 null。
     */
    private function resolveSubjectId(): ?int
    {
        $id = ActorContext::getId();

        if ($id === null || ! is_numeric($id)) {
            return null;
        }

        $subjectId = (int) $id;

        return $subjectId > 0 ? $subjectId : null;
    }

    /**
     * 归属自检
     */
    private function verifyOwnership(mixed $result, int $subjectId): mixed
    {
        $key = $this->subjectKey();

        if ($key === null) {
            return $result;
        }

        foreach ($this->rowsOf($result) as $row) {
            if (! array_key_exists($key, $row)) {
                return $this->violation(
                    'missing_subject_key',
                    "结果缺少归属列 [{$key}]，无法确认数据属于当前账号，已拒绝返回",
                );
            }

            if ((int) $row[$key] !== $subjectId) {
                return $this->violation(
                    'foreign_row',
                    '结果中包含不属于当前账号的数据，已拒绝返回',
                );
            }
        }

        return $result;
    }

    /**
     * 把结果摊成「行」列表
     *
     * 只认扁平结构：行数组（每项是关联数组）或单个关联数组。
     * 空结果与非数组（标量）都视为「没有行可检查」。
     *
     * @return array<int, array<string, mixed>>
     */
    private function rowsOf(mixed $result): array
    {
        if (! is_array($result) || $result === []) {
            return [];
        }

        // 单行：本身就是关联数组（有非数字键）
        if ($this->looksLikeRow($result)) {
            return [$result];
        }

        $rows = [];

        foreach ($result as $item) {
            if (is_array($item) && $this->looksLikeRow($item)) {
                $rows[] = $item;
            }
        }

        return $rows;
    }

    /**
     * @param  array<mixed>  $candidate
     */
    private function looksLikeRow(array $candidate): bool
    {
        foreach (array_keys($candidate) as $key) {
            if (! is_int($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{error: bool, denied: string, message: string}
     */
    private function violation(string $denied, string $message): array
    {
        return ['error' => true, 'denied' => $denied, 'message' => $message];
    }
}
