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
            ->with('status', "Project \"{$project->name}\" created.");
    }

    public function updated(Project $project): RedirectResponse
    {
        return redirect()
            ->route('projects.index')
            ->with('status', "Project \"{$project->name}\" updated.");
    }

    public function archived(Project $project): RedirectResponse
    {
        return redirect()
            ->route('projects.index')
            ->with('status', "Project \"{$project->name}\" archived.");
    }

    public function unarchived(Project $project): RedirectResponse
    {
        return redirect()
            ->route('projects.index')
            ->with('status', "Project \"{$project->name}\" restored.");
    }

    public function deleted(): RedirectResponse
    {
        return redirect()
            ->route('projects.index')
            ->with('status', 'Project deleted.');
    }
}
