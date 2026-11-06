<?php

namespace App\Presenters\SavedViews;

use App\Models\SavedView;

class SavedViewPresenter
{
    /**
     * Issues-page selector entry of a saved view: the name, the frozen
     * summary, the full definition (filters + sort) the client applies by
     * rebuilding the issues URL, and the creator's id so the UI can tell
     * "my view" apart (delete permission).
     *
     * @return array{id: string, name: string, summary: string|null, filters: array<string, mixed>, sort: string|null, dir: string|null, createdBy: int|null}
     */
    public static function present(SavedView $view): array
    {
        return [
            'id' => $view->id,
            'name' => $view->name,
            'summary' => $view->summary,
            'filters' => $view->filters ?? [],
            'sort' => $view->sort,
            'dir' => $view->dir,
            'createdBy' => $view->created_by,
        ];
    }
}
