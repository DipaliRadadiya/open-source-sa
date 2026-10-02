<?php

return [
    'not_installed' => ':addon no está instalado en este servidor.',
    'licence_required' => ':addon no se ha comprado para este servidor.',
    'site_not_registered' => 'Este sitio aún no está registrado en :addon.',
    'command_failed' => ':addon no pudo hacerlo: :message',
    'bad_output' => ':addon respondió algo que el panel no pudo leer.',
    'timed_out' => ':addon no terminó a tiempo.',
    'no_system_user' => 'Este sitio no tiene usuario del sistema.',
    'run_failed' => 'El comando del complemento falló inesperadamente.',
    'unregistered' => 'Sitio dado de baja.',
    'option_required' => ':option es obligatorio para este informe.',
    'redis_unavailable' => 'Redis no está en ejecución en este servidor, así que no se puede configurar Object Cache Pro.',
    'redis_too_old' => 'Object Cache Pro necesita Redis 6 o posterior para un acceso propio por sitio; este servidor tiene Redis :version.',
    'redis_no_password' => 'Primero establece una contraseña de Redis: sin ella, cualquier sitio podría leer la caché de los demás.',
    'redis_failed' => 'Redis no pudo crear el acceso del sitio.',
    'object_cache_not_enabled' => 'Object Cache Pro no está activado en este sitio.',
];
