<?php

return [
    'none' => 'aucun',
    'yes' => 'oui',
    'no' => 'non',
    // The server default PHP, where a cron job switched off plain `php`.
    'php_default' => 'par défaut',
    'adopted_left_unexpected_path' => 'son emplacement enregistré n\'est pas un emplacement que le panneau retirerait',
    'adopted_left_other_site' => 'il appartient à un autre site ou au serveur',
    'adopted_left_not_this_site' => 'il ne sert plus uniquement ce site',
    'adopted_left_unprovable_config' => 'il fait plus que servir ce site d\'une manière que le panneau ne peut pas vérifier : un bloc sans nom, le serveur par défaut, un include ou un alias hors du site',
    'adopted_left_not_regular' => 'ce n\'est pas un fichier ordinaire',
    'adopted_left_foreign_link' => 'le serveur web le charge via un lien ou une copie que le panneau n\'attendait pas',
    'adopted_left_unreadable' => 'il n\'a pas pu être lu',
    'adopted_left_config_test_failed' => 'le serveur web a refusé sa configuration sans lui, il a donc été remis en place',
    'adopted_left_remove_failed' => 'il n\'a pas pu être retiré, tout a donc été remis en place',
    'adopted_left_restore_failed' => 'le serveur web a refusé sa configuration sans lui et la remise en place a échoué — vérifiez maintenant la configuration du serveur web',
    'adopted_left_ols_shared' => 'il est déclaré dans la configuration partagée d\'OpenLiteSpeed, que le panneau ne modifie pas',
    'adopted_left_worker_shared' => 'c\'est un worker d\'un fichier de programmes partagé dont le panneau ne peut pas prouver la propriété, il continue donc de tourner',
    'adopted_left_worker_systemd' => 'c\'est un service systemd dont le panneau ne peut pas prouver la propriété, il continue donc de tourner',
    // The activity log's "what changed" line (2026-10-28).
    'on' => 'activé',
    'off' => 'désactivé',
    'with_changes' => ':sentence : :changes',
    'unchanged' => ':sentence (aucun changement)',
    'change' => ':field :from → :to',
    'change_secret' => ':field modifié',
    'change_secret_removed' => ':field supprimé',
];
