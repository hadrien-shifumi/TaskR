<?php

namespace App\Responders\Projects;

use App\Models\Project;
use Illuminate\Http\RedirectResponse;

class ProjectMemberRedirectResponder
{
    public function created(Project $project): RedirectResponse
    {
        return redirect()
            ->route('projects.members.index', $project)
            ->with('status', __('flash.project_member.created'));
    }

    public function updated(Project $project): RedirectResponse
    {
        return redirect()
            ->route('projects.members.index', $project)
            ->with('status', __('flash.project_member.updated'));
    }

    public function deleted(Project $project): RedirectResponse
    {
        return redirect()
            ->route('projects.members.index', $project)
            ->with('status', __('flash.project_member.deleted'));
    }
}
