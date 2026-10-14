<?php

namespace App\Actions\Templates;

use App\Enums\Priority;
use App\Models\IssueTemplate;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Templates\TemplateRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class UpdateIssueTemplate
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        return $this->currentUserIsOwner();
    }

    public function rules(): array
    {
        $template = request()->route('template');
        $workspaceId = $this->currentWorkspace()?->id;

        $unique = Rule::unique('issue_templates', 'name')
            ->where('workspace_id', $workspaceId);

        if ($template instanceof IssueTemplate) {
            $unique = $unique->ignore($template->id);
        }

        return [
            'name' => ['required', 'string', 'max:255', $unique],
            'description' => ['nullable', 'string', 'max:50000'],
            'priority' => ['nullable', 'string', 'in:'.implode(',', array_column(Priority::cases(), 'value'))],
            'label_ids' => ['nullable', 'array'],
            'label_ids.*' => ['ulid', Rule::exists('labels', 'id')->where('workspace_id', $workspaceId)],
        ];
    }

    /**
     * @param  array{name: string, description?: string|null, priority?: string|null, label_ids?: array<int, string>}  $data
     */
    public function handle(IssueTemplate $template, array $data): IssueTemplate
    {
        $template->update([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'priority' => $data['priority'] ?? null,
        ]);

        if (array_key_exists('label_ids', $data) && is_array($data['label_ids'])) {
            $template->labels()->sync($data['label_ids']);
        }

        return $template->refresh();
    }

    public function asController(ActionRequest $request, IssueTemplate $template, TemplateRedirectResponder $responder): RedirectResponse
    {
        $template = $this->handle($template, $request->validated());

        return $responder->updated($template);
    }

    protected function currentUserIsOwner(): bool
    {
        $user = request()->user();
        $workspace = $this->currentWorkspace();

        return $user instanceof User && $workspace !== null && $workspace->hasOwner($user);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
