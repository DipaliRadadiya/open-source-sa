<?php

return [

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'La sauvegarde de la base de données a échoué. Citez la référence ci-dessous au support.',
        'database_missing' => 'La base de données a été supprimée avant que l\'export puisse s\'exécuter.',
        'worker' => 'L\'export s\'est arrêté de manière inattendue. Il a peut-être expiré — réessayez.',
        'unknown' => 'L\'export a échoué. Citez la référence ci-dessous au support.',
    ],

];
