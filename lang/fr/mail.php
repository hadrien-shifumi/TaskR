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

    'reset' => [
        'subject' => 'Réinitialisez votre mot de passe TaskR',
        'greeting' => 'Bonjour :name,',
        'intro' => 'Vous avez demandé la réinitialisation du mot de passe de votre compte TaskR. Cliquez sur le bouton ci-dessous pour choisir un nouveau mot de passe.',
        'action' => 'Choisir un nouveau mot de passe',
        'expiry' => 'Ce lien est valable une heure et ne peut être utilisé qu\'une seule fois.',
        'ignore' => 'Si vous n\'êtes pas à l\'origine de cette demande, vous pouvez ignorer cet e-mail : votre mot de passe reste inchangé.',
        'footer' => 'Cet e-mail a été envoyé par TaskR à la suite d\'une demande de réinitialisation pour :email.',
    ],

    'invitation' => [
        'subject' => 'Invitation à rejoindre « :workspace » sur TaskR',
        'greeting' => 'Bonjour,',
        'intro' => ':inviter vous invite à rejoindre l\'espace de travail « :workspace » sur TaskR.',
        'role_line' => 'Votre rôle : :role.',
        'projects_line' => 'Projets : :projects',
        'action' => 'Accepter l\'invitation',
        'expiry' => 'Ce lien expire le :date.',
        'ignore' => 'Si vous n\'attendiez pas cette invitation, vous pouvez ignorer cet e-mail.',
        'footer' => 'Cet e-mail a été envoyé par TaskR car :inviter a invité :email à rejoindre « :workspace ».',
        'roles' => [
            'owner' => 'Propriétaire',
            'member' => 'Membre',
            'guest' => 'Invité',
        ],
    ],

];
