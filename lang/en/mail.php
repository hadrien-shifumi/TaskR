<?php

return [

    'digest' => [
        'subject' => 'Your TaskR digest — :workspace',
        'greeting' => 'Hello :name,',
        'intro' => 'Here is what moved in ":workspace".',
        'window' => 'Since :date.',
        'footer' => 'You receive this e-mail because the digest is enabled in your notification preferences. You can turn it off at any time from your account settings.',

        'sections' => [
            'overdue' => 'Overdue issues',
            'upcoming' => 'Upcoming due dates',
            'assignments' => 'New assignments',
            'comments' => 'New comments',
            'workload' => 'Your workload',
            'highlights' => 'Points of attention',
        ],

        'days_late' => ':count day late|:count days late',
        'days_until' => 'due in :count day|due in :count days',
        'due_today' => 'due today',
        'project' => 'Project: :name',
        'by' => 'by :name',

        'workload' => [
            'open_issues' => 'Open issues: :count',
            'overdue_issues' => 'Of which overdue: :count',
            'estimated_hours' => 'Estimated hours: :hours',
            'logged_hours' => 'Logged hours: :hours',
            'remaining_hours' => 'Remaining: :hours',
        ],

        'highlights' => [
            'overdue_issues' => ':count overdue issue|:count overdue issues',
            'gap' => 'Real progress :completion %, expected :expected %',
        ],
    ],

];
