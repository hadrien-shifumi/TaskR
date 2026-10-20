<?php

namespace App\Presenters\Templates;

use App\Models\IssueTemplate;

class TemplatePresenter
{
    /**
     * Creation-dialog option of an issue template.
     *
     * @return array{id: string, name: string, description: string|null, priority: string|null, labels: array<int, array{id: string, name: string}>}
     */
    public static function present(IssueTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'priority' => $template->priority?->value,
            'labels' => $template->labels->map(fn ($label) => ['id' => $label->id, 'name' => $label->name])->values()->all(),
        ];
    }
}
