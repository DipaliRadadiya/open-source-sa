<?php

return [
    'sync_failed' => "Échec de l'application de la tâche cron sur le serveur.",
    // One sentence per privileged step. They all used to share
    // `sync_failed`, so a full disk and a missing group read the same.
    'step' => [
        'log_dir' => "Le répertoire de journaux des tâches cron n'a pas pu être créé. Vérifiez l'espace disque et que /var/log est accessible en écriture.",
        'log_touch' => "Le fichier journal de la tâche cron n'a pas pu être créé. En général le disque est plein.",
        'log_chown' => "Le fichier journal n'a pas pu être attribué au compte qui exécute la tâche. Vérifiez que ce compte existe toujours.",
        'log_chmod' => "Les permissions du fichier journal n'ont pas pu être définies.",
        'rotation' => "La politique de rotation des journaux n'a pas pu être installée, la tâche n'a donc pas été planifiée : sa sortie grandirait sans limite.",
        'write' => "Le fichier cron n'a pas pu être écrit. Vérifiez l'espace disque disponible.",
        'chmod' => "Les permissions du fichier cron n'ont pas pu être définies. Cron ignore un fichier auquel il ne fait pas confiance, la tâche n'a donc pas été planifiée.",
        'remove' => "Le fichier cron n'a pas pu être supprimé, la tâche reste planifiée sur le serveur.",
        'remove_stale' => "L'ancien fichier cron n'a pas pu être supprimé après le renommage. Rien n'a été modifié, la tâche n'est donc pas planifiée deux fois.",
        'detach_source' => "Le fichier cron d'origine dont cette tâche a été importée n'a pas pu être supprimé. Rien n'a été modifié, la commande ne s'exécute donc pas deux fois.",
    ],
    'invalid_expression' => "La planification n'est pas une expression cron valide.",
    'invalid_user' => "L'utilisateur sélectionné n'existe pas sur le serveur.",
    'unresolved_placeholder' => "La commande contient encore le marqueur {path} — remplacez-le par le répertoire de l'application.",
    'no_newline' => 'Cette valeur ne peut pas contenir de sauts de ligne.',
    'reserved_name' => 'Ce nom est réservé et ne peut pas être utilisé.',
];
