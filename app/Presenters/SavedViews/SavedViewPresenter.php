<?php

namespace App\Presenters\SavedViews;

use App\Models\SavedView;
use App\Models\User;
use App\Support\SavedViewSummary;

class SavedViewPresenter
{
    /**
     * Issues-page selector entry of a saved view: the name, the summary,
     * the full definition (filters + sort) the client applies by
     * rebuilding the issues URL, and the creator's id so the UI can tell
     * "my view" apart (delete permission).
     *
     * The summary follows the invisibility doctrine: with a viewer in
     * hand it is recomputed from the stored definition, so the project
     * part only names a project the viewer may see. Without a viewer
     * (trusted internal calls) the stored, creator-locale summary ships
     * as is.
     *
     * @return array{id: string, name: string, summary: string|null, filters: array<string, mixed>, sort: string|null, dir: string|null, createdBy: int|null}
     */
    public static function present(SavedView $view, ?User $viewer = null): array
    {
        return [
            'id' => $view->id,
            'name' => $view->name,
            'summary' => $viewer !== null
                ? SavedViewSummary::make($view->workspace, $view->filters ?? [], $view->sort, $view->dir, $viewer)
                : $view->summary,
            'filters' => $view->filters ?? [],
            'sort' => $view->sort,
            'dir' => $view->dir,
            'createdBy' => $view->created_by,
        ];
    }
}
