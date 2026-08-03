<?php

return [

    /*
    | Why an export failed. Stored as a stable code on the row and worded here
    | at read time, so the sentence lands in the *viewer's* locale rather than
    | the locale of whoever pressed the button.
    */

    'export_failed' => [
        'dump_failed' => 'El volcado de la base de datos falló. Cite la referencia siguiente al soporte.',
        'database_missing' => 'La base de datos se eliminó antes de que la exportación pudiera ejecutarse.',
        'worker' => 'La exportación se detuvo inesperadamente. Puede haber expirado — inténtelo de nuevo.',
        'unknown' => 'La exportación falló. Cite la referencia siguiente al soporte.',
    ],

];
