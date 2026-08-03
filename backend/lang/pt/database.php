<?php

return [

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'O dump da base de dados falhou. Cite a referência abaixo ao suporte.',
        'database_missing' => 'A base de dados foi eliminada antes de a exportação poder ser executada.',
        'worker' => 'A exportação parou inesperadamente. Pode ter expirado — tente novamente.',
        'unknown' => 'A exportação falhou. Cite a referência abaixo ao suporte.',
    ],

];
