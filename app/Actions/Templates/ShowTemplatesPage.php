<?php

namespace App\Actions\Templates;

use App\Models\IssueTemplate;
use App\Models\Workspace;
use App\Responders\Templates\TemplatesPageResponder;
use App\Support\WorkspaceContext;
use Illuminate\Http\Request;
use Lorisleiva\Actions\Concerns\AsController;
use Lorisleiva\Actions\Concerns\AsObject;
use Symfony\Component\HttpFoundation\Response;

class ShowTemplatesPage
{
    use AsController;
    use AsObject;

    /**
     * @return array{templates: array<int, array<string, mixed>>, labels: array<array-key, array<string, mixed>>}
     */
    public function handle(Workspace $workspace): array
    {
        return [
            'templates' => $workspace->issueTemplates()
                ->with('labels')
                ->get()
                ->map(fn (IssueTemplate $template) => $this->present($template))
                ->values()
                ->all(),
            'labels' => $workspace->labels()->orderBy('name')->get(['id', 'name', 'color']),
        ];
    }

    public function asController(Request $request, TemplatesPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(IssueTemplate $template): array
    {
        return [
            'id' => $template->id,
            'name' => $template->name,
            'description' => $template->description,
            'priority' => $template->priority?->value,
            'labels' => $template->labels->map(fn ($label) => ['id' => $label->id, 'name' => $label->name])->values()->all(),
        ];
    }
}
