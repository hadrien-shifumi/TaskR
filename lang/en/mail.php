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

    'reset' => [
        'subject' => 'Reset your TaskR password',
        'greeting' => 'Hello :name,',
        'intro' => 'You asked for a password reset for your TaskR account. Click the button below to choose a new password.',
        'action' => 'Choose a new password',
        'expiry' => 'This link is valid for one hour and can be used only once.',
        'ignore' => 'If you did not request this reset, you can ignore this e-mail: your password stays unchanged.',
        'footer' => 'This e-mail was sent by TaskR following a password reset request for :email.',
    ],

];
