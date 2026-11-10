<?php

return [

    'issue' => [
        'parent_out_of_scope' => 'The parent issue is outside your project scope.',
        'project_out_of_scope' => 'This project is outside your project scope.',
        'blocker_out_of_scope' => 'A blocker issue is outside your project scope.',
        'knowledge_page_out_of_scope' => 'A knowledge page is outside your access scope.',
        'export_too_many' => 'The export is limited to :max tickets. Narrow the filters to reduce the volume.',
    ],

    'milestone' => [
        'target_date_before_start_date' => 'The target date must be after or equal to the start date.',
        'completed_before_start_date' => 'The completion date must be after or equal to the start date.',
        'parent_same_project' => 'The parent milestone must belong to the same project as the milestone.',
        'issue_same_project' => 'The milestone must belong to the same project as the issue.',
    ],

    'time_entry' => [
        'export_too_many' => 'The export is limited to :max time entries. Narrow the filters to reduce the volume.',
        'week_invalid' => 'The week must be a date in YYYY-MM-DD format.',
        'week_out_of_window' => 'The week must be the current one at the most recent, and at most eight weeks back.',
        'day_out_of_week' => 'This day does not belong to the submitted week.',
        'duplicate_cell' => 'A time entry already exists for this ticket on this day.',
    ],

    'timer' => [
        'issue_closed' => 'This issue is done or cancelled: the timer cannot start on it.',
        'already_running' => 'A timer is already running on this issue.',
        'stop_target_required' => 'Several timers are running: the one to stop must be designated.',
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

    'password' => [
        'wrong_current' => 'The current password is incorrect.',
    ],

    'invitation' => [
        'guest_requires_projects' => 'A guest must be attached to at least one project.',
        'already_member' => 'This address already belongs to a member of the workspace.',
    ],

    'member_scope' => [
        'members_only' => 'Only a member can carry a project perimeter: a guest is piloted through their project team, the owner has full access.',
    ],

    'attributes' => [
        'user_id' => 'member',
        'role' => 'role',
        'parent_id' => 'parent milestone',
        'milestone_id' => 'milestone',
    ],

];
