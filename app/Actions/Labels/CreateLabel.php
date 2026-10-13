<?php

namespace App\Actions\Labels;

use App\Models\Label;
use App\Models\User;
use App\Models\Workspace;
use App\Responders\Issues\LabelRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\Rule;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class CreateLabel
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();

        return $user instanceof User && app(WorkspaceContext::class)->isMember($user);
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required', 'string', 'max:100',
                Rule::unique('labels', 'name')->where('workspace_id', app(WorkspaceContext::class)->id()),
            ],
            'color' => ['nullable', 'string', 'max:50'],
        ];
    }

    /**
     * @param  array{name: string, color?: string|null}  $data
     */
    public function handle(Workspace $workspace, array $data): Label
    {
        return Label::create([
            'name' => $data['name'],
            'color' => $data['color'] ?? 'gray',
        ]);
    }

    public function asController(ActionRequest $request, LabelRedirectResponder $responder): RedirectResponse
    {
        $label = $this->handle(app(WorkspaceContext::class)->getOrFail(), $request->validated());

        return $responder->created($label);
    }
}
