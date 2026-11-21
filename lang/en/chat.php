<?php

/*
|--------------------------------------------------------------------------
| Chat (assistant) — backend strings
|--------------------------------------------------------------------------
|
| The displayable state of the chat V1: the error lines a turn reports
| on its thread (persisted on the assistant message and published on
| the private channel), and the labels the tools publish when a call
| starts. The stream fragments themselves come from the provider.
|
*/

return [

    'errors' => [
        // Without a chat provider configured for the workspace, the turn
        // cannot start: the assistant message settles failed and the
        // thread shows this line.
        'not_configured' => 'The assistant has no chat provider configured for this workspace. Ask the workspace owner to set one up in the AI settings.',
        // A provider failure (non-2xx, connection dead, mid-stream error).
        'provider_failed' => 'The assistant could not generate the reply. The chat provider refused the request — try again later.',
        // The turn's exploration budget ran out while the model kept
        // calling tools: the turn stops with an honest state.
        'exploration_exceeded' => 'The assistant stopped exploring after several lookups. Try a more direct question.',
        // Unexpected failure inside the turn (logged server-side).
        'unexpected' => 'The assistant hit an unexpected error. Try again later.',
    ],

    'tools' => [
        // The label published with every assistant tool call, keyed by
        // the tool's whitelist name (search_knowledge, list_issues…).
        'failed' => 'The tool call failed.',
        'unknown_tool' => 'The assistant tried to use an unavailable tool.',

        'search_knowledge' => 'Searching the knowledge base',
        'list_knowledge' => 'Browsing knowledge pages',
        'get_knowledge' => 'Reading a knowledge page',
        'list_projects' => 'Browsing projects',
        'list_issues' => 'Browsing issues',
        'get_issue' => 'Reading an issue',
        'list_comments' => 'Reading comments',
    ],

];
