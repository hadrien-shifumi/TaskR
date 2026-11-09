<?php

return [

    'accepted' => 'Le champ :attribute doit être accepté.',
    'active_url' => "Le champ :attribute n'est pas une URL valide.",
    'after' => 'Le champ :attribute doit être une date postérieure au :date.',
    'after_or_equal' => 'Le champ :attribute doit être une date postérieure ou égale au :date.',
    'before' => 'Le champ :attribute doit être une date antérieure au :date.',
    'before_or_equal' => 'Le champ :attribute doit être une date antérieure ou égale au :date.',
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'confirmed' => 'Le champ de confirmation :attribute ne correspond pas.',
    'date' => "Le champ :attribute n'est pas une date valide.",
    'email' => 'Le champ :attribute doit être une adresse email valide.',
    'exists' => 'Le champ :attribute sélectionné est invalide.',
    'in' => 'Le champ :attribute est invalide.',
    'integer' => 'Le champ :attribute doit être un entier.',
    'max' => [
        'numeric' => 'Le champ :attribute ne peut pas être supérieur à :max.',
        'file' => 'Le champ :attribute ne peut pas dépasser :max kilo-octets.',
        'string' => 'Le champ :attribute ne peut pas contenir plus de :max caractères.',
        'array' => 'Le champ :attribute ne peut pas contenir plus de :max éléments.',
    ],
    'min' => [
        'numeric' => 'Le champ :attribute doit être égal ou supérieur à :min.',
        'file' => 'Le champ :attribute doit faire au moins :min kilo-octets.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
    ],
    'not_in' => 'Le champ :attribute est invalide.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'present' => 'Le champ :attribute doit être présent.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_if' => 'Le champ :attribute est obligatoire quand :other a la valeur :value.',
    'required_unless' => 'Le champ :attribute est obligatoire sauf si :other a une valeur parmi :values.',
    'required_with' => 'Le champ :attribute est obligatoire quand :values est présent.',
    'required_without' => 'Le champ :attribute est obligatoire quand :values est absent.',
    'same' => 'Les champs :attribute et :other doivent être identiques.',
    'size' => [
        'numeric' => 'Le champ :attribute doit être égal à :size.',
        'file' => 'Le champ :attribute doit faire :size kilo-octets.',
        'string' => 'Le champ :attribute doit contenir :size caractères.',
        'array' => 'Le champ :attribute doit contenir :size éléments.',
    ],
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'unique' => 'La valeur du champ :attribute est déjà utilisée.',
    'url' => 'Le champ :attribute doit être une URL valide.',
    'uuid' => 'Le champ :attribute doit être un identifiant unique (UUID) valide.',

    'attributes' => [
        'email' => 'adresse email',
        'password' => 'mot de passe',
        'name' => 'nom',
        'title' => 'titre',
        'description' => 'description',
        'locale' => 'langue',
        'hours' => 'heures',
        'estimate' => 'estimation',
        'due_date' => "date d'échéance",
        'target_date' => 'date cible',
        'start_date' => 'date de début',
        'category' => 'catégorie',
        'status_id' => 'statut',
        'project_id' => 'projet',
        'parent_id' => 'échéance parente',
        'milestone_id' => 'échéance',
        'workspace_name' => "nom de l'espace de travail",
        'body' => 'contenu',
        'color' => 'couleur',
        'user_id' => 'membre',
        'role' => 'rôle',
    ],

    'issue' => [
        'parent_out_of_scope' => "Le ticket parent est en dehors de votre périmètre d'accès.",
        'project_out_of_scope' => "Ce projet est en dehors de votre périmètre d'accès.",
        'blocker_out_of_scope' => "Un ticket bloqueur est en dehors de votre périmètre d'accès.",
        'knowledge_page_out_of_scope' => "Une page de la base de connaissance est en dehors de votre périmètre d'accès.",
        'export_too_many' => "L'export est limité à :max tickets. Affinez les filtres pour réduire le volume à exporter.",
    ],

    'milestone' => [
        'target_date_before_start_date' => 'La date cible doit être postérieure ou égale à la date de début.',
        'completed_before_start_date' => 'La date de clôture doit être postérieure ou égale à la date de début.',
        'parent_same_project' => "L'échéance parente doit appartenir au même projet que l'échéance.",
        'issue_same_project' => "L'échéance doit appartenir au même projet que le ticket.",
    ],

    'time_entry' => [
        'export_too_many' => "L'export est limité à :max saisies de temps. Affinez les filtres pour réduire le volume à exporter.",
        'week_invalid' => 'La semaine doit être une date au format AAAA-MM-JJ.',
        'week_out_of_window' => 'La semaine doit être la semaine courante au plus récent, et au plus huit semaines en arrière.',
        'day_out_of_week' => "Ce jour n'appartient pas à la semaine soumise.",
        'duplicate_cell' => 'Une saisie de temps existe déjà pour ce ticket ce jour-là.',
    ],

    'timer' => [
        'issue_closed' => 'Ce ticket est terminé ou annulé : le chronomètre ne peut pas démarrer dessus.',
    ],

    'knowledge' => [
        'project_out_of_scope' => 'Une page de connaissance doit rester rattachée à un projet de votre périmètre.',
        'parent_out_of_scope' => "La page parente est en dehors de votre périmètre d'accès.",
        'conflict_detected' => 'Cette page a été modifiée par :editor pendant votre édition.',
    ],

    'project_member' => [
        'single_owner' => 'Un projet ne peut avoir qu\'un seul propriétaire.',
        'cannot_remove_owner' => 'Le propriétaire du projet ne peut pas être retiré du roster.',
        'guest_cannot_own_project' => 'Un invité Guest ne peut pas être propriétaire d\'un projet.',
        'cannot_remove_last_guest_project' => 'Un invité Guest doit conserver au moins un projet : retirez-le du workspace pour supprimer son accès.',
    ],

    'password' => [
        'wrong_current' => 'Le mot de passe actuel est incorrect.',
    ],

    'invitation' => [
        'guest_requires_projects' => 'Un invité Guest doit être rattaché à au moins un projet.',
    ],

    'member_scope' => [
        'members_only' => 'Seul un membre peut porter un périmètre de projets : le périmètre d\'un invité se pilote via son équipe projet et le propriétaire a accès à tout.',
    ],

];
