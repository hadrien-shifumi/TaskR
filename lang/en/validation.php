<?php

return [

    'issue' => [
        'parent_out_of_scope' => 'The parent issue is outside your project scope.',
        'project_out_of_scope' => 'This project is outside your project scope.',
        'blocker_out_of_scope' => 'A blocker issue is outside your project scope.',
    ],

    'knowledge' => [
        'project_out_of_scope' => 'A knowledge page must stay attached to a project of your scope.',
        'parent_out_of_scope' => 'The parent page is outside your project scope.',
        'conflict_detected' => 'This page was modified by :editor while you were editing.',
    ],

    'project_member' => [
        'single_owner' => 'A project can only have one owner.',
        'cannot_remove_owner' => 'The project owner cannot be removed from the roster.',
        'guest_cannot_own_project' => 'A guest cannot own a project.',
        'cannot_remove_last_guest_project' => 'A guest must keep at least one project: remove them from the workspace to revoke their access.',
    ],

    'invitation' => [
        'guest_requires_projects' => 'A guest must be attached to at least one project.',
        'member_cannot_carry_projects' => 'A member invitation carries no project scope.',
    ],

    'attributes' => [
        'user_id' => 'member',
        'role' => 'role',
    ],

];
