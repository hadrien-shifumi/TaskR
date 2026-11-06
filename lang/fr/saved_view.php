<?php

return [

    'summary' => [
        'project' => 'Projet : :value',
        'status' => 'Statut : :value',
        'category' => 'Catégorie : :value',
        'priority' => 'Priorité : :value',
        'labels' => 'Labels : :values',
        'assignee' => 'Assigné à : :value',
        'overdue' => 'En retard',
        'blocked' => 'Bloqués',
        'due_between' => 'Échéance entre le :after et le :before',
        'due_before' => 'Échéance avant le :date',
        'due_after' => 'Échéance après le :date',
        'search' => 'Recherche : « :value »',
        'sort' => 'Tri : :value',
    ],

    'categories' => [
        'backlog' => 'À faire',
        'in_progress' => 'En cours',
        'done' => 'Terminé',
        'cancelled' => 'Annulé',
    ],

    'priorities' => [
        'none' => 'aucune',
        'low' => 'basse',
        'high' => 'haute',
        'urgent' => 'urgente',
    ],

    'sorts' => [
        'created' => 'création',
        'priority' => 'priorité',
        'due_date' => 'échéance',
        'status' => 'statut',
        'project' => 'projet',
    ],

    'directions' => [
        'asc' => 'croissant',
        'desc' => 'décroissant',
    ],

];
