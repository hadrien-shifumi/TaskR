<?php

namespace App\Mcp\Support;

use App\Actions\Workspaces\ResolveAgentWorkspace;
use App\Models\Issue;
use App\Models\User;
use App\Models\Workspace;
use App\Support\EmbeddingsNotConfigured;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Support\ValidationMessages;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Shared execution envelope for every agent tool: a single error handler
 * (validation, authorization, not-found and business rules become structured
 * isError tool results — never a crashed session) and a guaranteed workspace
 * context flush after each call, so nothing leaks between calls.
 */
abstract class AgentTool extends Tool
{
    public function handle(Request $request): Response|ResponseFactory
    {
        try {
            return $this->execute($request);
        } catch (ValidationException $exception) {
            return Response::make(Response::error(ValidationMessages::from($exception)))
                ->withStructuredContent([
                    'error' => 'validation_failed',
                    'fields' => $exception->errors(),
                ]);
        } catch (AuthorizationException $exception) {
            return Response::make(Response::error($exception->getMessage()))
                ->withStructuredContent([
                    'error' => 'forbidden',
                    'message' => $exception->getMessage(),
                ]);
        } catch (ModelNotFoundException) {
            return Response::make(Response::error('The requested resource was not found in this workspace.'))
                ->withStructuredContent(['error' => 'not_found']);
        } catch (EmbeddingsNotConfigured $exception) {
            return Response::make(Response::error($exception->getMessage()))
                ->withStructuredContent([
                    'error' => 'embeddings_not_configured',
                    'message' => $exception->getMessage(),
                ]);
        } catch (HttpException $exception) {
            return Response::make(Response::error($exception->getMessage()))
                ->withStructuredContent([
                    'error' => 'business_rule',
                    'message' => $exception->getMessage(),
                ]);
        } finally {
            app(WorkspaceContext::class)->set(null);
        }
    }

    /**
     * @throws ValidationException|AuthorizationException|AuthenticationException
     */
    protected function resolveWorkspace(Request $request): Workspace
    {
        $workspaceId = $request->validate([
            'workspace_id' => ['required', 'ulid'],
        ])['workspace_id'];

        return ResolveAgentWorkspace::run($this->currentUser($request), $workspaceId);
    }

    /**
     * @throws AuthenticationException
     */
    protected function currentUser(Request $request): User
    {
        $user = $request->user();

        if (! $user instanceof User) {
            throw new AuthenticationException('Unauthenticated.');
        }

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentIssue(Issue $issue): array
    {
        return IssuePresenter::present($issue);
    }

    /**
     * @throws ValidationException|AuthorizationException|AuthenticationException
     */
    abstract protected function execute(Request $request): Response|ResponseFactory;
}
