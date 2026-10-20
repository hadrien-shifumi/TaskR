<?php

namespace App\Actions\Templates;

use App\Models\Workspace;
use App\Presenters\Labels\LabelPresenter;
use App\Presenters\Templates\TemplatePresenter;
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
                ->map(fn ($template) => TemplatePresenter::present($template))
                ->values()
                ->all(),
            'labels' => $workspace->labels()->orderBy('name')->get()->map(fn ($label) => LabelPresenter::present($label))->all(),
        ];
    }

    public function asController(Request $request, TemplatesPageResponder $responder): Response
    {
        $workspace = app(WorkspaceContext::class)->getOrFail();

        return $responder->toResponse($request, $this->handle($workspace));
    }
}
