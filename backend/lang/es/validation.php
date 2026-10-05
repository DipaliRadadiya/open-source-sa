<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | The following language lines contain the default error messages used by
    | the validator class. Some of these rules have multiple versions such
    | as the size rules. Feel free to tweak each of these messages here.
    |
    */

    'accepted' => 'El campo :attribute debe ser aceptado.',
    'accepted_if' => 'El campo :attribute debe ser aceptado cuando :other es :value.',
    'active_url' => 'El campo :attribute debe ser una URL válida.',
    'after' => 'El campo :attribute debe ser una fecha posterior a :date.',
    'after_or_equal' => 'El campo :attribute debe ser una fecha posterior o igual a :date.',
    'alpha' => 'El campo :attribute solo debe contener letras.',
    'alpha_dash' => 'El campo :attribute solo debe contener letras, números, guiones y guiones bajos.',
    'alpha_num' => 'El campo :attribute solo debe contener letras y números.',
    'array' => 'El campo :attribute debe ser un conjunto.',
    'ascii' => 'El campo :attribute solo debe contener caracteres alfanuméricos y símbolos de un solo byte.',
    'before' => 'El campo :attribute debe ser una fecha anterior a :date.',
    'before_or_equal' => 'El campo :attribute debe ser una fecha anterior o igual a :date.',
    'between' => [
        'array' => 'El campo :attribute debe tener entre :min y :max elementos.',
        'file' => 'El campo :attribute debe pesar entre :min y :max kilobytes.',
        'numeric' => 'El campo :attribute debe estar entre :min y :max.',
        'string' => 'El campo :attribute debe tener entre :min y :max caracteres.',
    ],
    'boolean' => 'El campo :attribute debe ser verdadero o falso.',
    'can' => 'El campo :attribute contiene un valor no autorizado.',
    'confirmed' => 'La confirmación de :attribute no coincide.',
    'current_password' => 'La contraseña es incorrecta.',
    'date' => 'El campo :attribute debe ser una fecha válida.',
    'date_equals' => 'El campo :attribute debe ser una fecha igual a :date.',
    'date_format' => 'El campo :attribute debe coincidir con el formato :format.',
    'decimal' => 'El campo :attribute debe tener :decimal cifras decimales.',
    'declined' => 'El campo :attribute debe ser rechazado.',
    'declined_if' => 'El campo :attribute debe ser rechazado cuando :other es :value.',
    'different' => 'Los campos :attribute y :other deben ser diferentes.',
    'digits' => 'El campo :attribute debe tener :digits dígitos.',
    'digits_between' => 'El campo :attribute debe tener entre :min y :max dígitos.',
    'dimensions' => 'El campo :attribute tiene dimensiones de imagen no válidas.',
    'distinct' => 'El campo :attribute tiene un valor duplicado.',
    'doesnt_end_with' => 'El campo :attribute no debe finalizar con uno de los siguientes: :values.',
    'doesnt_start_with' => 'El campo :attribute no debe comenzar con uno de los siguientes: :values.',
    'email' => 'El campo :attribute debe ser una dirección de correo válida.',
    'ends_with' => 'El campo :attribute debe finalizar con uno de los siguientes: :values.',
    'enum' => 'El campo :attribute seleccionado no es válido.',
    'exists' => 'El campo :attribute seleccionado no existe.',
    'extensions' => 'El campo :attribute debe tener una de las siguientes extensiones: :values.',
    'file' => 'El campo :attribute debe ser un archivo.',
    'filled' => 'El campo :attribute debe tener un valor.',
    'gt' => [
        'array' => 'El campo :attribute debe tener más de :value elementos.',
        'file' => 'El campo :attribute debe pesar más de :value kilobytes.',
        'numeric' => 'El campo :attribute debe ser mayor que :value.',
        'string' => 'El campo :attribute debe tener más de :value caracteres.',
    ],
    'gte' => [
        'array' => 'El campo :attribute debe tener :value elementos o más.',
        'file' => 'El campo :attribute debe pesar :value kilobytes o más.',
        'numeric' => 'El campo :attribute debe ser mayor o igual que :value.',
        'string' => 'El campo :attribute debe tener :value caracteres o más.',
    ],
    'hex_color' => 'El campo :attribute debe ser un color hexadecimal válido.',
    'image' => 'El campo :attribute debe ser una imagen.',
    'in' => 'El campo :attribute seleccionado no es válido.',
    'in_array' => 'El campo :attribute debe existir en :other.',
    'integer' => 'El campo :attribute debe ser un número entero.',
    'ip' => 'El campo :attribute debe ser una dirección IP válida.',
    'ipv4' => 'El campo :attribute debe ser una dirección IPv4 válida.',
    'ipv6' => 'El campo :attribute debe ser una dirección IPv6 válida.',
    'json' => 'El campo :attribute debe ser una cadena JSON válida.',
    'lowercase' => 'El campo :attribute debe estar en minúsculas.',
    'lt' => [
        'array' => 'El campo :attribute debe tener menos de :value elementos.',
        'file' => 'El campo :attribute debe pesar menos de :value kilobytes.',
        'numeric' => 'El campo :attribute debe ser menor que :value.',
        'string' => 'El campo :attribute debe tener menos de :value caracteres.',
    ],
    'lte' => [
        'array' => 'El campo :attribute debe tener :value elementos o menos.',
        'file' => 'El campo :attribute debe pesar :value kilobytes o menos.',
        'numeric' => 'El campo :attribute debe ser menor o igual que :value.',
        'string' => 'El campo :attribute debe tener :value caracteres o menos.',
    ],
    'mac_address' => 'El campo :attribute debe ser una dirección MAC válida.',
    'max' => [
        'array' => 'El campo :attribute no debe tener más de :max elementos.',
        'file' => 'El campo :attribute no debe pesar más de :max kilobytes.',
        'numeric' => 'El campo :attribute no debe ser mayor que :max.',
        'string' => 'El campo :attribute no debe tener más de :max caracteres.',
    ],
    'max_digits' => 'El campo :attribute no debe tener más de :max dígitos.',
    'mimes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'mimetypes' => 'El campo :attribute debe ser un archivo de tipo: :values.',
    'min' => [
        'array' => 'El campo :attribute debe tener al menos :min elementos.',
        'file' => 'El campo :attribute debe pesar al menos :min kilobytes.',
        'numeric' => 'El campo :attribute debe ser al menos :min.',
        'string' => 'El campo :attribute debe tener al menos :min caracteres.',
    ],
    'min_digits' => 'El campo :attribute debe tener al menos :min dígitos.',
    'missing' => 'El campo :attribute debe estar ausente.',
    'missing_if' => 'El campo :attribute debe estar ausente cuando :other es :value.',
    'missing_unless' => 'El campo :attribute debe estar ausente a menos que :other sea :value.',
    'missing_with' => 'El campo :attribute debe estar ausente cuando :values está presente.',
    'missing_with_all' => 'El campo :attribute debe estar ausente cuando :values están presentes.',
    'multiple_of' => 'El campo :attribute debe ser múltiplo de :value.',
    'not_in' => 'El campo :attribute seleccionado no es válido.',
    'not_regex' => 'El formato del campo :attribute no es válido.',
    'numeric' => 'El campo :attribute debe ser un número.',
    'password' => [
        'letters' => 'El campo :attribute debe contener al menos una letra.',
        'mixed' => 'El campo :attribute debe contener al menos una letra mayúscula y una minúscula.',
        'numbers' => 'El campo :attribute debe contener al menos un número.',
        'symbols' => 'El campo :attribute debe contener al menos un símbolo.',
        'uncompromised' => 'El :attribute proporcionado ha aparecido en una filtración de datos. Elige un :attribute diferente.',
    ],
    'present' => 'El campo :attribute debe estar presente.',
    'present_if' => 'El campo :attribute debe estar presente cuando :other es :value.',
    'present_unless' => 'El campo :attribute debe estar presente a menos que :other sea :value.',
    'present_with' => 'El campo :attribute debe estar presente cuando :values está presente.',
    'present_with_all' => 'El campo :attribute debe estar presente cuando :values están presentes.',
    'prohibited' => 'El campo :attribute está prohibido.',
    'prohibited_if' => 'El campo :attribute está prohibido cuando :other es :value.',
    'prohibited_unless' => 'El campo :attribute está prohibido a menos que :other sea :values.',
    'prohibits' => 'El campo :attribute prohíbe que :other esté presente.',
    'regex' => 'El formato del campo :attribute no es válido.',
    'required' => 'El campo :attribute es obligatorio.',
    'required_array_keys' => 'El campo :attribute debe contener entradas para: :values.',
    'required_if' => 'El campo :attribute es obligatorio cuando :other es :value.',
    'required_if_accepted' => 'El campo :attribute es obligatorio cuando :other es aceptado.',
    'required_unless' => 'El campo :attribute es obligatorio a menos que :other esté en :values.',
    'required_with' => 'El campo :attribute es obligatorio cuando :values está presente.',
    'required_with_all' => 'El campo :attribute es obligatorio cuando :values están presentes.',
    'required_without' => 'El campo :attribute es obligatorio cuando :values no está presente.',
    'required_without_all' => 'El campo :attribute es obligatorio cuando ninguno de :values está presente.',
    'same' => 'Los campos :attribute y :other deben coincidir.',
    'size' => [
        'array' => 'El campo :attribute debe contener :size elementos.',
        'file' => 'El campo :attribute debe pesar :size kilobytes.',
        'numeric' => 'El campo :attribute debe ser :size.',
        'string' => 'El campo :attribute debe tener :size caracteres.',
    ],
    'starts_with' => 'El campo :attribute debe comenzar con uno de los siguientes: :values.',
    'string' => 'El campo :attribute debe ser una cadena de texto.',
    'timezone' => 'El campo :attribute debe ser una zona horaria válida.',
    'unique' => 'El campo :attribute ya ha sido tomado.',
    'uploaded' => 'El campo :attribute no se pudo subir.',
    'uppercase' => 'El campo :attribute debe estar en mayúsculas.',
    'url' => 'El campo :attribute debe ser una URL válida.',
    'ulid' => 'El campo :attribute debe ser un ULID válido.',
    'uuid' => 'El campo :attribute debe ser un UUID válido.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    */

    'any_of' => 'El campo :attribute no es válido.',
    'base64' => 'El campo :attribute debe ser una cadena Base64 válida.',
    'contains' => 'Al campo :attribute le falta un valor requerido.',
    'doesnt_contain' => 'El campo :attribute no debe contener ninguno de los siguientes: :values.',
    'encoding' => 'El campo :attribute debe estar codificado en :encoding.',
    'in_array_keys' => 'El campo :attribute debe contener al menos una de las siguientes claves: :values.',
    'list' => 'El campo :attribute debe ser una lista.',
    'prohibited_if_accepted' => 'El campo :attribute está prohibido cuando :other es aceptado.',
    'prohibited_if_declined' => 'El campo :attribute está prohibido cuando :other es rechazado.',
    'required_if_declined' => 'El campo :attribute es obligatorio cuando :other es rechazado.',

    'custom' => [

        // Docker accepts a CPU quota larger than the machine and silently
        // clamps it, so the refusal has to come from here — and it names what
        // the server actually has rather than saying "invalid".
        'cpu_limit' => [
            'format' => 'Indique un número de CPU, con hasta dos decimales — 1, 1.5, 0.5.',
            'positive' => 'El límite de CPU más pequeño que Docker acepta es :minimum. Deje el campo vacío para no poner límite.',
            'too_many' => 'Este servidor tiene :cores CPU, y Docker no arrancará un contenedor que pida más. Elija :cores o menos.',
        ],

        'memory_limit' => [
            'format' => 'Indique un tamaño con unidad — 512m o 2g. Un número solo significa bytes para Docker, no megabytes.',
            'too_small' => 'Docker no arrancará un contenedor con menos de 6m de memoria.',
        ],

        // A registry address Docker cannot interpret is silently IGNORED at
        // pull time — the credential simply never applies and the error is
        // identical to having none. So these are refusals at the form, and each
        // one names the specific mistake rather than saying "invalid".
        'registry' => [
            'empty' => 'Introduzca la dirección del registro: `docker.io` para Docker Hub, `ghcr.io` o su propio host.',
            'path' => 'Eso parece un espacio de nombres o un repositorio, no un registro. Introduzca solo el host: `ghcr.io`, no `ghcr.io/su-org`.',
            'credentials' => 'No ponga un usuario ni una contraseña en la dirección. Introduzca solo el host; las credenciales van en los campos de abajo.',
            'host' => 'Esa no es una dirección de registro. Introduzca un nombre de host, opcionalmente con puerto: `registry.example.com` o `registry.example.com:5000`.',
            'port' => 'El puerto debe estar entre 1 y 65535.',
        ],
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],

        // Laravel's default reads "The docker network new field prohibits docker
        // network from being present" — raw attribute names at a user.
        'docker_network_new' => [
            'prohibits' => 'Elige una red de la lista o nombra una nueva, pero no las dos cosas: son dos respuestas a la misma pregunta.',
        ],

        'volume_path' => [
            'required_with' => 'Indica la ruta dentro del contenedor donde debe aparecer este volumen, por ejemplo /var/lib/mysql.',
        ],

        'volume_new' => [
            'required_with' => 'Escribe el nombre del volumen que se creará, o borra la ruta si no quieres ninguno.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    */

    'attributes' => [
        'name' => 'nombre',
        'username' => 'nombre de usuario',
        'password' => 'contraseña',
        'current_password' => 'contraseña actual',
        'role' => 'rol',
    ],

    'start_command_shell' => 'El comando de inicio no puede contener ":token": se ejecuta directamente, no a través de un shell.',
    'start_command_wrapper' => 'Inicia la app con su archivo de entrada, por ejemplo "node server.js", no con :binary. Un gestor de paquetes bifurca el proceso real, así que las señales nunca le llegan.',
    'start_command_shell' => 'El comando de inicio no puede contener ":token": se ejecuta directamente, no a través de un shell.',
    'process_instances_entrypoint' => 'Para ejecutar más de un proceso, el comando de inicio debe nombrar un script, por ejemplo «node server.js». PM2 agrupa haciendo fork de ese archivo; con cualquier otra cosa ejecuta en silencio un solo proceso.',
    'start_command_wrapper' => 'Inicia la app con su archivo de entrada, por ejemplo "node server.js", no con :binary. Un gestor de paquetes bifurca el proceso real, así que las señales nunca le llegan.',

    'port_in_use_by_app' => 'El puerto :port ya lo usa otra aplicación en este servidor.',

    // A network the panel created, named by a site that will join it.
    'docker_network_invalid' => 'Empieza el nombre de la red con una letra o un número y después usa letras, números, puntos, guiones o guiones bajos.',
    'docker_network_missing' => 'En este servidor no hay ninguna red de Docker llamada \':name\'. Puede que se haya eliminado desde que se cargó esta página.',

    // Mounting a panel-created volume into a container site.
    'docker_volume_invalid' => 'Empieza el nombre del volumen con una letra o un número y después usa letras, números, puntos, guiones o guiones bajos.',
    'docker_volume_missing' => 'En este servidor no hay ningún volumen de Docker llamado \':name\'. Puede que se haya eliminado desde que se cargó esta página.',
    'docker_mount_duplicate' => 'Dos volúmenes no pueden montarse en :path. Docker conservaría solo uno, sin indicar cuál.',
    'docker_mount_root' => 'Elige una ruta dentro del contenedor, por ejemplo /var/lib/mysql.',
    'docker_mount_site_root' => 'En esa ruta se montan los archivos propios del sitio (:path). Un volumen ahí los oculta del contenedor: los archivos siguen en el servidor, pero el sitio sirve un volumen vacío.',
    'docker_mount_reserved' => ':path forma parte de la imagen con la que arranca el contenedor. Un volumen vacío encima deja un contenedor que no puede arrancar.',

    // A name the panel is about to create, so the inverse rule: not taken.
    'docker_network_taken' => 'Ya existe una red llamada \':name\' en este servidor. Elígela en la lista de arriba en lugar de crear una segunda.',
    'docker_volume_taken' => 'Ya existe un volumen llamado \':name\' en este servidor. Monta el existente desde la tarjeta Contenedor del sitio en lugar de crear un segundo.',
    'node_version_unsupported' => 'La aplicación :type funciona con Node :range. Elige una versión dentro de ese rango: fuera de él la aplicación se niega a iniciarse y el sitio no sirve nada.',
    'php_version_unsupported' => 'La aplicación :type funciona con PHP :range. Elige una versión dentro de ese rango: fuera de él la instalación falla a medias, dentro del código de la propia aplicación, y deja un sitio que hay que limpiar.',
    'php_version_default_unsupported' => 'La aplicación :type funciona con PHP :range. Dejar este campo vacío usa el valor predeterminado del servidor (:default), que está fuera de ese rango: elige una versión dentro del rango.',
    'web_root_fixed' => ':type se sirve desde :web_root y se instala en torno a esa ruta, así que la raíz web no se puede cambiar aquí. Cualquier otro valor deja el sitio inaccesible y publica su código fuente.',
    'web_root_missing' => 'La carpeta :path no existe, así que el sitio no tendría nada que servir. Créala o sube primero tus archivos allí y después establécela como raíz web.',
    'port_in_use' => 'Algo en este servidor ya está escuchando en el puerto :port. Elige otro o detén lo que lo usa.',

    'port_registered' => 'El puerto :port lo usa normalmente :service. Puedes usarlo igualmente si nada en este servidor lo hace.',
    'application_name_immutable' => 'El nombre de un sitio es fijo una vez creado: da nombre a sus archivos de configuración en el servidor. Crea un sitio nuevo si necesitas otro nombre.',
    'site_name_taken_pool' => 'Este servidor ya tiene un archivo de pool de PHP llamado «:name.conf» que no creó el panel. Elige otro nombre.',
    'site_name_taken_vhost' => 'Este servidor ya tiene una configuración del servidor web llamada «:name» que no creó el panel — la del propio panel es una de ellas. Elige otro nombre.',

    'webhook_secret_min' => 'El secreto del webhook debe tener al menos 16 caracteres.',

    // Per-app fail2ban thresholds
    'fail2ban.validation.maxretry_min' => 'Debe bloquear tras al menos 1 intento fallido.',
    'fail2ban.validation.maxretry_max' => 'No puede bloquear tras más de 100 intentos fallidos.',
    'fail2ban.validation.findtime_min' => 'La ventana de conteo debe ser de al menos 60 segundos.',
    'fail2ban.validation.findtime_max' => 'La ventana de conteo no puede superar las 24 horas (86400 segundos).',
    'fail2ban.validation.bantime_min' => 'El tiempo de bloqueo debe ser de al menos 0 segundos (se permite bloqueo permanente).',
    'fail2ban.validation.bantime_max' => 'El tiempo de bloqueo no puede superar los 7 días (604800 segundos).',
    'ip_or_cidr' => 'Debe ser una dirección IP válida (p. ej. 1.2.3.4) o notación CIDR (p. ej. 10.0.0.0/8).',

    'jail_content_required' => 'La configuración de la jaula es obligatoria.',
    'jail_content_string' => 'La configuración de la jaula debe ser texto.',
    'jail_content_max' => 'La configuración de la jaula es demasiado grande (máx. 65535 caracteres).',
    'filter_content_required' => 'La configuración del filtro es obligatoria.',
    'filter_content_string' => 'La configuración del filtro debe ser texto.',
    'filter_content_max' => 'La configuración del filtro es demasiado grande (máx. 65535 caracteres).',
    'basic_auth_conflicts' => 'La protección con contraseña no se puede usar con :type. Su propia interfaz inicia sesión con la cabecera Authorization, que HTTP solo permite una vez por petición: la autenticación básica la consumiría y dejaría la aplicación inaccesible. :type ya requiere sus propias credenciales.',
    'basic_auth_username' => 'El nombre de usuario solo puede contener letras inglesas, dígitos y símbolos, sin espacios ni dos puntos (:). Los navegadores no pueden enviar otros caracteres de forma fiable.',
    'git_repository_unreachable' => 'No se pudo acceder al repositorio. Comprueba la dirección y que la cuenta (o el repositorio, si es público) permite el acceso.',
    'git_host_unreachable' => 'El servidor no pudo conectar con el servidor git. Comprueba su conexión de red e inténtalo de nuevo.',
    'git_branch_missing' => 'La rama ":branch" no existe en este repositorio.',
];
