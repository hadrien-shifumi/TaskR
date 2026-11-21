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
        'not_configured' => "L'assistant n'a pas de fournisseur de chat configuré pour cet espace de travail. Demande au propriétaire de le configurer dans les réglages d'IA.",
        // A provider failure (non-2xx, connection dead, mid-stream error).
        'provider_failed' => "L'assistant n'a pas pu générer la réponse. Le fournisseur de chat a refusé la demande — réessaie plus tard.",
        // The turn's exploration budget ran out while the model kept
        // calling tools: the turn stops with an honest state.
        'exploration_exceeded' => "L'assistant a interrompu son exploration après plusieurs recherches. Réessaie avec une question plus directe.",
        // Unexpected failure inside the turn (logged server-side).
        'unexpected' => "L'assistant a rencontré une erreur inattendue. Réessaie plus tard.",
    ],

    'tools' => [
        // The label published with every assistant tool call, keyed by
        // the tool's whitelist name (search_knowledge, list_issues…).
        'failed' => "L'appel d'outil a échoué.",
        'unknown_tool' => "L'assistant a tenté d'utiliser un outil indisponible.",

        'search_knowledge' => 'Recherche dans la base de connaissances',
        'list_knowledge' => 'Parcours des pages de connaissances',
        'get_knowledge' => "Lecture d'une page de connaissances",
        'list_projects' => 'Parcours des projets',
        'list_issues' => 'Parcours des tickets',
        'get_issue' => "Lecture d'un ticket",
        'list_comments' => 'Lecture des commentaires',
    ],

];
