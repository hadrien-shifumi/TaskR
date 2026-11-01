<?php

return [

    'digest' => [
        'subject' => 'Votre récapitulatif TaskR — :workspace',
        'greeting' => 'Bonjour :name,',
        'intro' => 'Voici ce qui a bougé dans « :workspace ».',
        'window' => 'Depuis le :date.',
        'footer' => 'Vous recevez cet e-mail car le récapitulatif est activé dans vos préférences de notification. Vous pouvez le désactiver à tout moment depuis les réglages de votre compte.',

        'sections' => [
            'overdue' => 'Tickets en retard',
            'upcoming' => 'Échéances proches',
            'assignments' => 'Nouvelles assignations',
            'comments' => 'Nouveaux commentaires',
            'workload' => 'Votre charge',
            'highlights' => 'Points d\'attention',
        ],

        'days_late' => ':count jour de retard|:count jours de retard',
        'days_until' => 'échéance dans :count jour|échéance dans :count jours',
        'due_today' => 'échéance aujourd\'hui',
        'project' => 'Projet : :name',
        'by' => 'par :name',

        'workload' => [
            'open_issues' => 'Tickets ouverts : :count',
            'overdue_issues' => 'Dont en retard : :count',
            'estimated_hours' => 'Heures estimées : :hours',
            'logged_hours' => 'Heures saisies : :hours',
            'remaining_hours' => 'Reste à faire : :hours',
        ],

        'highlights' => [
            'overdue_issues' => ':count ticket en retard|:count tickets en retard',
            'gap' => 'Avancement réel :completion %, attendu :expected %',
        ],
    ],

];
