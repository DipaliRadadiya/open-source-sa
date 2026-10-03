<?php

return [
    'presets' => [
        'low' => [
            'title' => 'Tráfico bajo',
            'description' => 'Un par de procesos. Adecuado para la mayoría de sitios pequeños y lo más amable con un servidor pequeño.',
        ],
        'balanced' => [
            'title' => 'Equilibrado',
            'description' => 'Soporta tráfico normal sin reservar memoria que rara vez necesita.',
        ],
        'high' => [
            'title' => 'Tráfico alto',
            'description' => 'Mantiene procesos listos. Úsalo cuando el sitio esté realmente ocupado: reserva memoria se use o no.',
        ],
    ],

    'disable_functions_presets' => [
        'safe' => [
            'title' => 'Recomendado',
            'description' => 'Bloquea todas las formas de ejecutar un programa desde PHP: lo que necesita un web shell y lo que un sitio normal casi nunca hace.',
        ],
        'strict' => [
            'title' => 'Estricto',
            'description' => 'Añade inspección de procesos, usuarios y sockets sobre la lista recomendada. Equivale al endurecimiento habitual del alojamiento compartido y puede romper un sitio que use la extensión sockets.',
        ],
    ],

    'errors' => [
        'missing_account' => 'La cuenta de Linux con la que se ejecuta este sitio no existe en el servidor, así que no se escribió ningún pool de PHP. PHP-FPM no arranca en absoluto con un pool cuyo usuario no puede resolver.',
        'version_not_installed' => 'PHP :version no está instalado en este servidor. Instálelo primero y luego selecciónelo aquí.',
        'version_busy' => 'PHP :version todavía se está instalando o eliminando. Espere a que termine y luego cambie la versión.',
        'directive_invalid' => '":line" no es un ajuste de PHP. Usa un ajuste por línea, como display_errors = Off.',
        'directive_managed' => '":line" cambia :name, que gestiona el panel, así que no se puede definir aquí. Si esta pantalla tiene un campo para ello, úsalo.',
        'directive_extension' => '":line" carga una extensión de PHP. Usa la pantalla de extensiones de PHP.',
        'unsupported_stack' => 'Este servidor usa OpenLiteSpeed, que no utiliza pools de PHP-FPM.',
        'not_php_site' => 'Este sitio no sirve PHP, así que no hay ningún pool que asignarle. Cambia primero cómo se sirve.',
        'already_isolated' => 'Este sitio ya tiene su propio pool de PHP.',
        'not_isolated' => 'Este sitio no está aislado.',
        'needs_isolation' => 'Este sitio aún no tiene su propio grupo (pool) de PHP, así que estos límites no se podrían aplicar. Asígnale uno primero y luego guarda.',
        'basedir_absolute' => 'Cada ruta debe ser absoluta y empezar por /. «:path» no lo es.',
        'basedir_root' => '«/» permite todo el sistema de archivos, lo que dejaría open_basedir activado sin aplicar nada. Desactiva la opción en su lugar.',
        'basedir_traversal' => '«:path» no está permitida: las rutas no pueden contener «..».',
        'write_failed' => 'No se pudo escribir la configuración del pool. No se cambió nada.',
        'config_test_failed' => 'PHP-FPM rechazó la configuración, así que no se aplicó ni se recargó nada. El sitio se sigue sirviendo exactamente igual que antes.',
        'reload_failed' => 'PHP-FPM no se recargó, así que se restauró la configuración anterior.',
        'no_sections' => 'Aquí no se permiten encabezados de sección: iniciarían un segundo pool dentro de este.',
        'function_list' => 'Debe ser una lista de nombres de funciones separados por comas.',
        'memory_unlimited' => 'No se permite memoria ilimitada (-1): un sitio podría usar toda la memoria del servidor y tumbar todos los demás sitios. Introduce un límite, como 512M.',
        'memory_over_ram' => 'Esto es más memoria de la que tiene el servidor (:ram). Introduce un límite menor.',
        'post_below_upload' => 'El tamaño máximo de POST (:post) debe ser al menos el tamaño máximo de subida (:upload). Una subida se envía dentro de la petición, así que si no, las subidas más grandes fallan sin ningún error.',
        'prepend_outside_site' => 'El archivo debe estar dentro de la carpeta de este sitio (:root/…).',
    ],
];
