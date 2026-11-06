<?php

namespace App\Actions\SavedViews;

use App\Actions\Concerns\AuthorizesWorkspaceAccess;
use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\SavedViews\SavedViewRedirectResponder;
use App\Support\IssueFilterSanitizer;
use App\Support\SavedViewSummary;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateSavedView
{
    use AsController;
    use AsObject;
    use AuthorizesWorkspaceAccess;

    public function authorize(): bool
    {
        // Belonging is permission: any member (guests included) may name
        // and save the current filters as a shared view.
        return $this->currentUserCanUseWorkspace();
    }

    public function rules(): array
    {
        $workspaceId = $this->currentWorkspace()?->id;

        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('saved_views', 'name')->where('workspace_id', $workspaceId)],
        ];
    }

    /**
     * Persist a named snapshot of the issues-page filters and sort. The
     * filter values are sanitized exactly like the page reads them, the
     * summary is frozen in the creator's locale, and the page number
     * never enters a saved view.
     *
     * @param  array{name: string, filters?: array<string, mixed>, sort?: mixed, dir?: mixed}  $data
     */
    public function handle(Workspace $workspace, ?User $creator, array $data): SavedView
    {
        $filters = $this->sanitizeFilters($data['filters'] ?? []);

        $sort = IssueFilterSanitizer::sort($data['sort'] ?? null);
        $dir = IssueFilterSanitizer::direction($data['dir'] ?? null);

        return SavedView::create([
            'name' => $data['name'],
            'summary' => SavedViewSummary::make($workspace, $filters, $sort, $dir),
            'filters' => $filters === [] ? null : $filters,
            'sort' => $sort,
            'dir' => $dir,
            'created_by' => $creator?->getKey(),
        ]);
    }

    public function asController(ActionRequest $request, SavedViewRedirectResponder $responder): RedirectResponse
    {
        $view = $this->handle($this->currentWorkspace(), $request->user(), [
            'name' => $request->validated('name'),
            'filters' => [
                'project' => $request->input('project'),
                'status' => $request->input('status'),
                'category' => $request->input('category'),
                'priority' => $request->input('priority'),
                'label' => $request->input('label'),
                'assignee' => $request->input('assignee'),
                'overdue' => $request->input('overdue'),
                'blocked' => $request->input('blocked'),
                'due_before' => $request->input('due_before'),
                'due_after' => $request->input('due_after'),
                'q' => $request->input('q'),
            ],
            'sort' => $request->input('sort'),
            'dir' => $request->input('dir'),
        ]);

        return $responder->created($view);
    }

    /**
     * Keep only the well-formed filter values, exactly like the issues
     * page reads them: invalid values are dropped silently — a shared URL
     * never turns into an error.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, string|array<int, string>>
     */
    private function sanitizeFilters(array $filters): array
    {
        $sanitized = [
            'project' => IssueFilterSanitizer::ulid($filters['project'] ?? null),
            'status' => IssueFilterSanitizer::ulid($filters['status'] ?? null),
            'category' => IssueFilterSanitizer::category($filters['category'] ?? null),
            'priority' => IssueFilterSanitizer::priority($filters['priority'] ?? null),
            'label' => IssueFilterSanitizer::labels($filters['label'] ?? null),
            'assignee' => IssueFilterSanitizer::positiveInt($filters['assignee'] ?? null),
            'overdue' => IssueFilterSanitizer::flag($filters['overdue'] ?? null),
            'blocked' => IssueFilterSanitizer::flag($filters['blocked'] ?? null),
            'due_before' => IssueFilterSanitizer::date($filters['due_before'] ?? null),
            'due_after' => IssueFilterSanitizer::date($filters['due_after'] ?? null),
            'q' => IssueFilterSanitizer::search($filters['q'] ?? null),
        ];

        return array_filter($sanitized, fn ($value) => $value !== null);
    }
}
