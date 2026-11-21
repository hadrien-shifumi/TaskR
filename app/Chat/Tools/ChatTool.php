<?php

namespace App\Chat\Tools;

use App\Models\User;
use App\Models\Workspace;
use App\Support\EmbeddingsNotConfigured;
use App\Support\WorkspaceContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

/**
 * The base of every tool TaskR's assistant may call: a whitelist name, a
 * provider-facing JSON Schema, and one run through the exact Laravel
 * action the screens share — never a bespoke query.
 *
 * The run sets the workspace context before the action executes and always
 * flushes it afterwards (pattern App\Mcp\Support\AgentTool::handle): the
 * queued turn borrows the requesting user's identity for one call at a
 * time, and nothing — workspace, project scope, memos — survives a failed
 * or a successful call. Every failure answers as a structured tool result
 * the model can read and react to: never an exception escaping into the
 * turn, never more than the user's own perimeter.
 */
abstract class ChatTool
{
    /**
     * The whitelist name, identical to the MCP tool's (search_knowledge,
     * list_issues…) so the same mental model maps to both surfaces.
     */
    abstract public function name(): string;

    /**
     * How the model decides to call the tool.
     */
    abstract public function description(): string;

    /**
     * The parameters the model may pass, as a JSON-Schema property set:
     * `['name' => ['type' => 'string', 'description' => '…']]`, with
     * `'required' => true` flags where the call needs the parameter. The
     * workspace never appears — the loop holds it, the model cannot name
     * another one.
     *
     * @return array<string, array<string, mixed>>
     */
    abstract public function schema(): array;

    /**
     * The Laravel validation the arguments pass before the action runs —
     * the same shape and limits the screens validate. Workspace-scoped
     * existence checks resolve against the given workspace.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<array-key, mixed>
     */
    abstract protected function rules(Workspace $workspace): array;

    /**
     * The action call itself: the same one the screens and the MCP tools
     * run, with the actor's real user. Returns the compact payload the
     * model reads (the actions already present the trimmed forms).
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    abstract protected function execute(array $validated, Workspace $workspace, User $user): array;

    /**
     * Validate then execute the tool for the given actor. Every failure
     * becomes a structured result (`ok: false` + an error kind), never an
     * exception; the workspace context is borrowed and restored around the
     * action, whatever the outcome.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function run(array $arguments, Workspace $workspace, User $user): array
    {
        $context = app(WorkspaceContext::class);
        $context->set($workspace);

        try {
            $validated = Validator::validate($arguments, $this->rules($workspace));

            return [
                'ok' => true,
                'result' => $this->execute($validated, $workspace, $user),
            ];
        } catch (ValidationException $exception) {
            return [
                'ok' => false,
                'error' => 'validation_failed',
                'fields' => $exception->errors(),
            ];
        } catch (AuthorizationException $exception) {
            return [
                'ok' => false,
                'error' => 'forbidden',
                'message' => $exception->getMessage(),
            ];
        } catch (ModelNotFoundException) {
            return [
                'ok' => false,
                'error' => 'not_found',
                'message' => 'The requested resource was not found in this workspace.',
            ];
        } catch (EmbeddingsNotConfigured $exception) {
            return [
                'ok' => false,
                'error' => 'embeddings_not_configured',
                'message' => $exception->getMessage(),
            ];
        } catch (HttpException $exception) {
            return [
                'ok' => false,
                'error' => 'business_rule',
                'message' => $exception->getMessage(),
            ];
        } catch (Throwable $exception) {
            report($exception);

            return [
                'ok' => false,
                'error' => 'tool_failed',
                'message' => 'The tool could not complete.',
            ];
        } finally {
            $context->set(null);
        }
    }

    /**
     * The OpenAI function-calling definition this registry serializes:
     * name + description + parameters, the required flags lifted out of
     * the schema into the `required` list.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $properties = [];
        $required = [];

        foreach ($this->schema() as $name => $property) {
            if (($property['required'] ?? false) === true) {
                $required[] = $name;
            }

            $properties[$name] = array_diff_key($property, ['required' => true]);
        }

        return [
            'type' => 'function',
            'function' => [
                'name' => $this->name(),
                'description' => $this->description(),
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                ],
            ],
        ];
    }
}
