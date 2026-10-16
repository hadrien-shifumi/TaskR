<?php

namespace App\Responders\Projects;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class ProjectRedirectResponder
{
    public function created(Project $project): RedirectResponse
    {
        return redirect()
            ->route('projects.index')
            ->with('status', __('flash.project.created', ['name' => $project->name]));
    }

    public function updated(Project $project): RedirectResponse
    {
        return redirect()
            ->route('projects.index')
            ->with('status', __('flash.project.updated', ['name' => $project->name]));
    }

    public function archived(Project $project): RedirectResponse
    {
        return redirect()
            ->route('projects.index')
            ->with('status', __('flash.project.archived', ['name' => $project->name]));
    }

    public function unarchived(Project $project): RedirectResponse
    {
        return redirect()
            ->route('projects.index')
            ->with('status', __('flash.project.restored', ['name' => $project->name]));
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->route('projects.index')
            ->with('status', __('flash.project.deleted'));
    }
}
