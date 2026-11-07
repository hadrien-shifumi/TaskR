<?php

namespace App\Actions\Statuses;

use App\Models\User;
use App\Models\Workspace;
use App\Responders\Statuses\StatusRedirectResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Lorisleiva\Actions\ActionRequest;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ReorderStatuses
{
    use AsController;
    use AsObject;

    public function authorize(): bool
    {
        $user = request()->user();
        $workspace = $this->currentWorkspace();

        return $user instanceof User && $workspace !== null && $workspace->hasOwner($user);
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['string', 'ulid'],
        ];
    }

    /**
     * @param  array{ids: array<string>}  $data
     */
    public function handle(Workspace $workspace, array $data): void
    {
        DB::transaction(function () use ($workspace, $data): void {
            foreach (array_values($data['ids']) as $position => $id) {
                $workspace->statuses()
                    ->whereKey($id)
                    ->update(['position' => $position]);
            }
        });

        // The bulk update bypasses model events: drop the statuses cache
        // explicitly so the next read sees the new order.
        Workspace::flushStatusesCache($workspace->getKey());
    }

    public function asController(ActionRequest $request, StatusRedirectResponder $responder): RedirectResponse
    {
        $this->handle($this->currentWorkspace(), $request->validated());

        return $responder->updated(null);
    }

    protected function currentWorkspace(): ?Workspace
    {
        return app(WorkspaceContext::class)->get();
    }
}
