<?php

namespace App\Actions\Templates;

use App\Models\IssueTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Templates\TemplateRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class DeleteIssueTemplate
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = $this->currentWorkspace();

        return $user instanceof User && $workspace !== null && $workspace->hasOwner($user);
    }

    /**
     * No blocking condition: issues never reference a template, so deletion
     * cannot affect any existing issue.
     */
    public function handle(IssueTemplate $template): IssueTemplate
    {
        $template->delete();

        return $template;
    }

    public function asController(IssueTemplate $template, TemplateRedirectResponder $responder): RedirectResponse
    {
        $this->handle($template);

        return $responder->deleted();
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
