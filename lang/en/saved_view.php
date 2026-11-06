<?php

return [

    'summary' => [
        'project' => 'Project: :value',
        'status' => 'Status: :value',
        'category' => 'Category: :value',
        'priority' => 'Priority: :value',
        'labels' => 'Labels: :values',
        'assignee' => 'Assigned to: :value',
        'overdue' => 'Overdue',
        'blocked' => 'Blocked',
        'due_between' => 'Due between :after and :before',
        'due_before' => 'Due before :date',
        'due_after' => 'Due after :date',
        'search' => 'Search: ":value"',
        'sort' => 'Sort: :value',
    ],

    'categories' => [
        'backlog' => 'Backlog',
        'in_progress' => 'In progress',
        'done' => 'Done',
        'cancelled' => 'Cancelled',
    ],

    'priorities' => [
        'none' => 'none',
        'low' => 'low',
        'high' => 'high',
        'urgent' => 'urgent',
    ],

    'sorts' => [
        'created' => 'creation',
        'priority' => 'priority',
        'due_date' => 'due date',
        'status' => 'status',
        'project' => 'project',
    ],

    'directions' => [
        'asc' => 'ascending',
        'desc' => 'descending',
    ],

];
