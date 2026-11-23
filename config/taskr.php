<?php

return [

    // Maximum number of rows an export (tickets, time entries) may
    // contain; beyond, the export is refused and the user is invited
    // to narrow the filters.
    'export_max_rows' => 50_000,

    // Instance-wide feature switches. They belong to the deployment
    // (the .env), never to a workspace: a flag decides whether a whole
    // section of the app is served, nothing here is per-tenant.
    'features' => [

        // Exposes the Chatbot section: the navigation item and the two
        // /chatbot pages. Off by default — the owner's decision of
        // 2026-11-21 to keep the section asleep until it is re-opened;
        // the JSON chat endpoints and the AI settings screen stay live.
        'chat' => (bool) env('TASKR_FEATURE_CHAT', false),

    ],

];
