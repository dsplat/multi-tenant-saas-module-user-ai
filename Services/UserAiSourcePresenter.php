<?php

declare(strict_types=1);

namespace MultiTenantSaas\Modules\UserAi\Services;

class UserAiSourcePresenter
{
    public function present(array $sources, int $limit = 5): array
    {
        if (isset($sources['results']) && is_array($sources['results'])) {
            $sources = $sources['results'];
        }

        $presented = [];

        foreach (array_slice($sources, 0, $limit) as $item) {
            if (! is_array($item)) {
                $text = trim((string) $item);
                if ($text !== '') {
                    $presented[] = ['content' => mb_substr($text, 0, 500)];
                }

                continue;
            }

            $content = trim((string) ($item['content'] ?? $item['text'] ?? $item['title'] ?? ''));
            if ($content === '') {
                continue;
            }

            $row = ['content' => mb_substr($content, 0, 500)];
            $title = trim((string) ($item['title'] ?? $item['document_name'] ?? ''));
            if ($title !== '') {
                $row['title'] = mb_substr($title, 0, 120);
            }

            $presented[] = $row;
        }

        return $presented;
    }

    public function fromToolResult(string $tool, mixed $result): array
    {
        if ($tool !== 'knowledge_search' || ! is_array($result)) {
            return [];
        }

        return $this->present($result);
    }
}
