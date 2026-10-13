<?php

namespace App\Actions\Statuses;

use App\Models\Workspace;
use App\Responders\Statuses\StatusesPageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;

class ShowStatusesPage
{
    use AsController;
    use AsObject;

    /**
     * @return array{statuses: array<array-key, array<string, mixed>>}
     */
    public function handle(Workspace $workspace): array
    {
        return [
            'statuses' => $workspace->statuses()
                ->get()
                ->map(fn ($status) => [
                    'id' => $status->id,
                    'name' => $status->name,
                    'category' => $status->category->value,
                    'position' => $status->position,
                ])
                ->values()
                ->all(),
        ];
    }

    public function asController(Request $request, StatusesPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace));
    }
}
