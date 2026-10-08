<?php

return [
    'presets' => [
        'low' => [
            'title' => 'Tráfico bajo',
            'description' => 'Un par de procesos. Adecuado para la mayoría de aplicaciones pequeñas y lo más amable con un servidor pequeño.',
        ],
        'balanced' => [
            'title' => 'Equilibrado',
            'description' => 'Soporta tráfico normal sin reservar memoria que rara vez necesita.',
        ],
        'high' => [
            'title' => 'Tráfico alto',
            'description' => 'Mantiene procesos listos. Úsalo cuando la aplicación esté realmente ocupada: reserva memoria se use o no.',
        ],
    ],

    'disable_functions_presets' => [
        'safe' => [
            'title' => 'Recomendado',
            'description' => 'Bloquea todas las formas de ejecutar un programa desde PHP: lo que necesita un web shell y lo que una aplicación normal casi nunca hace.',
        ],
        'strict' => [
            'title' => 'Estricto',
            'description' => 'Añade inspección de procesos, usuarios y sockets sobre la lista recomendada. Equivale al endurecimiento habitual del alojamiento compartido y puede romper una aplicación que use la extensión sockets.',
        ],
    ],

    'errors' => [
        'missing_account' => 'La cuenta de Linux con la que se ejecuta esta aplicación no existe en el servidor, así que no se escribió ningún pool de PHP. PHP-FPM no arranca en absoluto con un pool cuyo usuario no puede resolver.',
        'version_not_installed' => 'PHP :version no está instalado en este servidor. Instálelo primero y luego selecciónelo aquí.',
        'version_busy' => 'PHP :version todavía se está instalando o eliminando. Espere a que termine y luego cambie la versión.',
        'directive_invalid' => '":line" no es un ajuste de PHP. Usa un ajuste por línea, como display_errors = Off.',
        'directive_managed' => '":line" cambia :name, que gestiona el panel, así que no se puede definir aquí. Si esta pantalla tiene un campo para ello, úsalo.',
        'directive_extension' => '":line" carga una extensión de PHP. Usa la pantalla de extensiones de PHP.',
        'unsupported_stack' => 'Este servidor usa OpenLiteSpeed, que no utiliza pools de PHP-FPM.',
        'not_php_site' => 'Esta aplicación no sirve PHP, así que no hay ningún pool que asignarle. Cambia primero cómo se sirve.',
        'already_isolated' => 'Esta aplicación ya tiene su propio pool de PHP.',
        'not_isolated' => 'Esta aplicación no está aislada.',
        'needs_isolation' => 'Esta aplicación aún no tiene su propio grupo (pool) de PHP, así que estos límites no se podrían aplicar. Asígnale uno primero y luego guarda.',
        'basedir_absolute' => 'Cada ruta debe ser absoluta y empezar por /. «:path» no lo es.',
        'basedir_root' => '«/» permite todo el sistema de archivos, lo que dejaría open_basedir activado sin aplicar nada. Desactiva la opción en su lugar.',
        'basedir_traversal' => '«:path» no está permitida: las rutas no pueden contener «..».',
        'write_failed' => 'No se pudo escribir la configuración del pool. No se cambió nada.',
        'config_test_failed' => 'PHP-FPM rechazó la configuración, así que no se aplicó ni se recargó nada. La aplicación se sigue sirviendo exactamente igual que antes.',
        'reload_failed' => 'PHP-FPM no se recargó, así que se restauró la configuración anterior.',
        'no_sections' => 'Aquí no se permiten encabezados de sección: iniciarían un segundo pool dentro de este.',
        'function_list' => 'Debe ser una lista de nombres de funciones separados por comas.',
        'memory_unlimited' => 'No se permite memoria ilimitada (-1): una aplicación podría usar toda la memoria del servidor y tumbar todas las demás aplicaciones. Introduce un límite, como 512M.',
        'memory_over_ram' => 'Esto es más memoria de la que tiene el servidor (:ram). Introduce un límite menor.',
        'memory_too_low' => 'Es muy poca memoria: PHP interpreta un número sin unidad como bytes, así que la aplicación no podría arrancar. Introduce al menos 32M, por ejemplo :suggestion.',
        'post_below_upload' => 'El tamaño máximo de POST (:post) debe ser al menos el tamaño máximo de subida (:upload). Una subida se envía dentro de la petición, así que si no, las subidas más grandes fallan sin ningún error.',
        'prepend_outside_site' => 'El archivo debe estar dentro de la carpeta de esta aplicación (:root/…).',
    ],
];
