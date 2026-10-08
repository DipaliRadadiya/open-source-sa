<?php

return [
    // What a name attached to an application does. Shown as the badge
    // beside each domain, so it has to read as a noun, not a sentence.
    'domain_type' => [
        'primary' => 'Principal',
        'alias' => 'Alias',
        'redirect' => 'Redirección',
    ],

    'types' => [
        'docker' => ['title' => 'Contenedor Docker', 'tagline' => 'Cualquier imagen, de cualquier registro, servida a través de nginx.'],
        'ghost' => ['title' => 'Ghost', 'tagline' => 'Publicación y boletines: funciona con su propio MySQL'],
        'matomo' => ['title' => 'Matomo', 'tagline' => 'Analítica web en tu propio servidor: una alternativa a Google Analytics'],
        'mattermost' => ['title' => 'Mattermost', 'tagline' => 'Chat de equipo: funciona con su propio PostgreSQL'],
        'chatwoot' => ['title' => 'Chatwoot', 'tagline' => 'Bandeja de soporte al cliente — funciona con su propio PostgreSQL y Redis'],
        'excalidraw' => ['title' => 'Excalidraw', 'tagline' => 'Pizarra — los dibujos se quedan en tu navegador; compartir y colaborar en vivo pasan por excalidraw.com'],
        'forgejo' => ['title' => 'Forgejo', 'tagline' => 'Alojamiento Git: bifurcación de Gitea gobernada por la comunidad'],
        'freshrss' => ['title' => 'FreshRSS', 'tagline' => 'Lector de feeds autoalojado'],
        'gitea' => ['title' => 'Gitea', 'tagline' => 'Alojamiento Git con incidencias y pull requests'],
        'glance' => ['title' => 'Glance', 'tagline' => 'Panel de feeds, monitores y marcadores'],
        'homepage' => ['title' => 'Homepage', 'tagline' => 'Página de inicio para los servicios de tu servidor'],
        'ittools' => ['title' => 'IT-Tools', 'tagline' => 'Utilidades para desarrolladores: no se guarda nada'],
        'stirlingpdf' => ['title' => 'Stirling PDF', 'tagline' => 'Divide, combina, firma y convierte PDF en el navegador'],
        'vaultwarden' => ['title' => 'Vaultwarden', 'tagline' => 'Gestor de contraseñas compatible con las apps de Bitwarden'],
        'nocodb' => ['title' => 'NocoDB', 'tagline' => 'Interfaz de hoja de cálculo para una base de datos: funciona con su propio PostgreSQL'],
        'metabase' => ['title' => 'Metabase', 'tagline' => 'Paneles y consultas sobre tus datos: funciona con su propio PostgreSQL'],
        'wikijs' => ['title' => 'Wiki.js', 'tagline' => 'Wiki de documentación: funciona con su propio PostgreSQL'],
        'grafana' => ['title' => 'Grafana', 'tagline' => 'Paneles y alertas sobre sus métricas: la contraseña de administrador se genera por aplicación'],
        'bookstack' => ['title' => 'BookStack', 'tagline' => 'Documentación en estantes, libros y páginas: funciona con su propia MariaDB'],
        'wordpress_container' => ['title' => 'WordPress', 'tagline' => 'Creador de blogs y sitios web: se ejecuta en un contenedor con su propia MariaDB'],
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Creador de blogs y sitios web'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Gestione sus bases de datos en el navegador'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'Monitorización de disponibilidad y páginas de estado'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'Automatización de flujos de trabajo (licencia fair-code)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'Conecta dispositivos, APIs y servicios'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'Software de foros — necesita MongoDB o PostgreSQL'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Sincronización y uso compartido de archivos privados'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'Sistema de gestión de contenidos flexible'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'Cursos y aprendizaje en línea'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'Automatización de marketing y campañas'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => 'Gestión de contenidos para desarrolladores'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => 'Contabilidad y facturación'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'CMS de archivos planos, sin base de datos'],
        'prestashop' => ['title' => 'PrestaShop', 'tagline' => 'Tienda en línea y comercio electrónico'],
        'git' => ['title' => 'Desde un repositorio Git', 'tagline' => 'Despliega tu propio código desde GitHub, GitLab o Bitbucket'],
        'php' => ['title' => 'Aplicación PHP vacía', 'tagline' => 'Una aplicación vacía: sube tus propios archivos'],
        'static' => ['title' => 'Aplicación estática', 'tagline' => 'HTML, CSS y JavaScript simples'],
    ],

    'status' => [
        'pending' => 'Aún no desplegado',
        'provisioning' => 'Configurando…',
        'active' => 'En ejecución',
        'failed' => 'Error de configuración',
    ],

    'unavailable' => [
        'stack' => 'Este servidor solo ejecuta contenedores, por lo que no aloja este tipo de aplicación.',
        'stack_profile' => 'Este servidor no está preparado para alojar este tipo de aplicación.',
        'profile' => 'Este servidor no sirve aplicaciones :profile, así que este tipo de renderizado no se puede usar aquí. Elige uno que este servidor sirva.',
        'database' => 'Esta aplicación necesita :engines, que este servidor no tiene.',
        'php' => 'Este servidor no tiene PHP instalado.',
        'php_version_install' => 'Este servidor no tiene ninguna versión de PHP en la que funcione :type (:range). Instala primero PHP :version desde la pantalla de PHP.',
        'php_version_none' => 'Este servidor no tiene ninguna versión de PHP en la que funcione :type (:range), y ninguna de esas versiones se puede instalar desde el repositorio de paquetes del servidor.',
        'node' => 'Este servidor no tiene Node.js instalado.',
        'web_server' => 'Esta aplicación aún no está disponible en servidores :web_server.',
    ],

    'git_source' => [
        'account' => 'Desde una cuenta conectada',
        'public_url' => 'Pegar la URL de un repositorio público',
    ],

    'fields' => [
        'memory_limit' => 'Límite de memoria',
        'cpu_limit' => 'Límite de CPU',
        'compose' => 'Archivo compose',
        'image' => 'Imagen',
        'registry_id' => 'Registro',
        'container_port' => 'Puerto del contenedor',
        'docker_network' => 'Red',
        'docker_mode' => 'Cómo ejecutarlo',
        'docker_network_new' => 'Red nueva',
        'volume_new' => 'Volumen nuevo',
        'volume_path' => 'Ruta del volumen',
        'database_engine' => 'Motor de base de datos',
        'company_name' => 'Nombre de la empresa',
        'company_email' => 'Correo de la empresa',
        'locale' => 'Configuración regional',
        'site_name' => 'Nombre de la aplicación',
        'language' => 'Idioma',
        'admin_name' => 'Nombre del administrador',
        'admin_first_name' => 'Nombre del administrador',
        'admin_last_name' => 'Apellidos del administrador',
        'short_name' => 'Nombre corto',
        'shop_name' => 'Nombre de la tienda',
        'country' => 'País',
        'timezone' => 'Zona horaria',
        'rendering_type' => 'Tipo de renderizado',
        'name' => 'Nombre',
        'domain' => 'Dominio',
        'system_user_id' => 'Usuario del sistema',
        'php_version' => 'Versión de PHP',
        'node_version' => 'Versión de Node.js',
        'app_port' => 'Puerto de la aplicación',
        'web_root' => 'Raíz web',
        'build_command' => 'Comando de compilación',
        'deploy_script' => 'Script de despliegue',
        'start_command' => 'Comando de inicio',
        'package_manager' => 'Gestor de paquetes',
        'git_source' => 'Origen',
        'git_account_id' => 'Cuenta de Git',
        'repository' => 'Repositorio',
        'repository_url' => 'URL del repositorio',
        'branch' => 'Rama',
        'site_title' => 'Título de la aplicación',
        'admin_user' => 'Usuario administrador',
        'admin_username' => 'Usuario administrador',
        'admin_email' => 'Correo del administrador',
        'admin_password' => 'Contraseña del administrador',
        'site_language' => 'Idioma de la aplicación',
        'table_prefix' => 'Prefijo de tablas',
        'mailer_name' => 'Nombre del remitente',
        'mailer_email' => 'Dirección del remitente',
        'mailer_host' => 'Servidor SMTP',
        'mailer_port' => 'Puerto SMTP',
        'mailer_username' => 'Usuario SMTP',
        'mailer_password' => 'Contraseña SMTP',
    ],

    /*
    | Example values, shown as ghost text in an empty field.
    |
    | A placeholder is NOT a default: it is never submitted. Anything with a
    | correct value the panel can pick lives in the field's `default` instead,
    | which the form pre-fills and the request carries — a table prefix is a
    | default, an email address is a placeholder. Getting that backwards ships
    | a form that looks filled in and posts null.
    |
    | Keyed by field name, not by site type, so one entry serves every type
    | declaring that field — the same arrangement as `fields` and `help`.
    | Localized because these are read by a person: an example is only an
    | example if it is in a language they read.
    */
    'placeholders' => [
        'cpu_limit' => 'Sin límite',
        'mailer_host' => 'smtp.ejemplo.com',
        'mailer_port' => '587',
        'site_title' => 'Mi aplicación',
        'site_name' => 'Mi aplicación',
        'shop_name' => 'Mi tienda',
        'company_name' => 'Mi empresa',
        'short_name' => 'misitio',
        'mailer_name' => 'Mi aplicación',
        'admin_email' => 'tu@ejemplo.com',
        'company_email' => 'tu@ejemplo.com',
        'mailer_email' => 'no-reply@ejemplo.com',
        'mailer_username' => 'no-reply@ejemplo.com',
        'timezone' => 'Europe/Madrid',
        'repository_url' => 'https://github.com/tu/repo.git',
        'build_command' => 'npm ci && npm run build',
        'start_command' => 'node server.js',
    ],

    'options' => [
        'docker_mode' => [
            'simple' => 'Simple: una imagen y un puerto',
            'compose' => 'Archivo compose: lo escribes tú',
        ],
    ],

    'help' => [
        'memory_limit_app' => 'Opcional. La memoria máxima que puede usar el contenedor de esta aplicación, por ejemplo 512m o 2g. Un número sin unidad significa bytes para Docker, no megabytes. Si se deja vacío recibe :default. Cualquier base de datos que traiga la aplicación tiene su propio techo.',
        'memory_limit_app_floor' => 'Opcional. La memoria máxima que puede usar el contenedor de esta aplicación. Si se deja vacío recibe :default, la cifra que se ha medido que esta aplicación necesita: por debajo puede no arrancar, y fallará como un 502 sin nada que mencione la memoria. Cualquier base de datos que traiga la aplicación tiene su propio techo.',
        'cpu_limit_app' => 'Opcional. Cuántos núcleos de CPU puede usar el contenedor de esta aplicación — 1 para un núcleo completo, 0.5 para la mitad. Este servidor tiene :cores, y Docker no arrancará un contenedor que pida más. Si se deja vacío no hay límite de CPU. Superarlo no mata nada: el contenedor espera, así que el síntoma es lentitud y no un error. Cualquier base de datos que traiga la aplicación no queda limitada.',
        'memory_limit' => 'Opcional. La memoria máxima que este contenedor puede usar, por ejemplo 512m o 2g. Un número sin unidad significa bytes para Docker, no megabytes. Si se deja vacío recibe el valor por defecto del servidor: :default. Es un techo, no una reserva — no se aparta nada — y superarlo hace que el contenedor se mate y se reinicie.',
        'cpu_limit' => 'Opcional. Cuántos núcleos de CPU puede usar este contenedor — 1 para un núcleo completo, 0.5 para la mitad. Este servidor tiene :cores, y Docker no arrancará un contenedor que pida más. Si se deja vacío no hay límite de CPU alguno. Superar este no mata nada: el contenedor espera, así que el síntoma es lentitud y no un error.',
        'compose' => 'Opcional. Pega tu propio archivo compose y todo lo que Compose admite estará disponible: varios servicios, volúmenes con nombre, healthchecks. Déjalo vacío y el panel escribirá uno a partir de los campos anteriores. Los puertos deben publicarse en 127.0.0.1 y los montajes deben permanecer dentro del directorio de esta aplicación; cualquier otra cosa se rechaza indicando el motivo.',
        'image' => 'La imagen a ejecutar, con una etiqueta explícita: `nginx:1.27-alpine`. Un nombre sin etiqueta usa `latest`, lo que hace que un despliegue no sea reproducible y que revertirlo no signifique nada.',
        'registry_id' => 'Déjelo vacío para una imagen pública: es el caso normal. Para ejecutar una privada, añada una credencial en la página de Docker, en «Credenciales de registro», y elíjala aquí. También se aplica a un compose pegado, ya que cualquier imagen que nombre puede ser privada.',
        'container_port' => 'El puerto en el que tu aplicación escucha dentro del contenedor. El panel asigna el puerto en el propio servidor y apunta nginx a él.',
        'docker_network' => 'Únete a una red de Docker para que este contenedor y los demás de esa red puedan localizarse por su nombre. Déjalo vacío para usar el puente predeterminado de Docker, donde no pueden. Las redes se crean en la página de Docker.',
        'docker_mode' => 'Simple te pide una imagen y un puerto, y el panel escribe el archivo compose. Archivo compose es para todo lo demás: lo escribes tú, y el panel sigue imponiendo publicación en loopback, un límite de memoria y logs acotados.',
        'docker_network_new' => 'Deja vacío el selector de arriba y escribe aquí un nombre para crear una red nueva y unir esta aplicación a ella. Se rechaza si ya existe una red con ese nombre: en ese caso, elígela arriba.',
        'volume_new' => 'Crea un volumen con este nombre y lo monta en la aplicación, para que sus datos sobrevivan a la reconstrucción del contenedor. Se rechaza si ya existe un volumen con ese nombre.',
        'volume_path' => 'Donde aparece el volumen dentro del contenedor, por ejemplo /var/lib/ghost/content. No la carpeta propia de la aplicación: un volumen ahí ocultaría sus archivos.',
        'table_prefix_random' => 'Déjalo vacío y se generará un prefijo aleatorio, manteniendo las tablas separadas si alguna vez se comparte la base de datos.',
        'timezone' => 'Zona horaria de la aplicación, p. ej. America/New_York o Europe/Madrid. Ver Ajustes → General → Zona horaria.',
        'table_prefix_optional' => 'Opcional. Si lo borras, las tablas se crean sin ningún prefijo.',
        'start_command' => 'El archivo de entrada, por ejemplo "node server.js". No "npm start": un gestor de paquetes bifurca el proceso real, así que las señales de apagado nunca le llegan.',
        'app_port' => 'Si lo dejas vacío, el panel elige uno libre.',
        'rendering_type' => 'El renderizado en servidor ejecuta tu app y hace de proxy hacia ella. Los otros dos compilan a archivos que el servidor web entrega directamente: más rápido y sin nada que mantener en ejecución.',
        'repository_url' => 'Un repositorio público: no hace falta cuenta. Debe ser una dirección https://.',
        'build_command' => 'Se ejecuta tras descargar el código, p. ej. composer install --no-dev',
        'deploy_script' => 'Se ejecuta después de obtener el código, como el usuario de la aplicación y con la versión de PHP de esta aplicación. Déjelo vacío para usar el comando de compilación.',
        'package_manager' => 'Lo que instala y compila tus dependencias. Rellena el comando de compilación de abajo; edítalo libremente después.',
    ],

    'steps' => [
        'create_database' => 'Creando la base de datos',
        'download' => 'Descargando la aplicación',
        'extract' => 'Descomprimiendo los archivos',
        'configure' => 'Escribiendo la configuración',
        'install_cli' => 'Instalando la herramienta de instalación',
        'install_app' => 'Ejecutando el instalador',
        'init' => 'Configurando el repositorio',
        'fetch' => 'Descargando el código más reciente',
        'checkout' => 'Cambiando a la rama',
        'seed_env' => 'Preparando el archivo de entorno',
        'build' => 'Ejecutando el comando de compilación',
        'write_credential' => 'Preparando el acceso a git',
        'ensure_account' => 'Creando la cuenta del sistema',
        'create_directory' => 'Creando el directorio',
        'set_ownership' => 'Estableciendo la propiedad',
        'placeholder' => 'Añadiendo una página de marcador',
        'write_config' => 'Escribiendo la configuración de la aplicación',
        'test_config' => 'Probando la configuración',
        'reload' => 'Recargando el servidor web',
        'start_app' => 'Iniciando la aplicación',
        'write_unit' => 'Preparando el servicio',
        'restart_app' => 'Reiniciando la aplicación',
        'harden' => 'Aplicando ajustes de seguridad',
        'trust_domain' => 'Autorizando el dominio',
        'set_password' => 'Estableciendo la contraseña de administrador',
        'script' => 'Ejecutando el script de despliegue',
        'dependencies' => 'Comprobando las dependencias',
        'verify' => 'Comprobando que la aplicación responde',
        'verify_serving' => 'Comprobando que la aplicación responde',
        'create_admin' => 'Creando la cuenta de administrador',
        'schedule_cron' => 'Programando las tareas en segundo plano',
        'worker' => 'El proceso en segundo plano se detuvo',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'site_type_change' => [
        'git_cannot_change' => 'Esta aplicación se despliega desde un repositorio git, así que su tipo no se puede cambiar. Sus pantallas de Despliegues, Workers y archivo de entorno existen por ese tipo, y quitarlas no detendría los workers en segundo plano ni evitaría que el webhook de despliegue acepte pushes: solo eliminaría las pantallas que los gestionan.',
        'git_not_a_target' => 'Una aplicación no puede convertirse en un despliegue git. Eso requiere un repositorio, una rama y un script de despliegue que el panel controle, y eso no se puede crear a partir de los archivos que ya están en el servidor. Crea una aplicación git en su lugar.',
        'unchanged' => 'Esta aplicación ya está configurada con ese tipo.',
        'not_suggestable' => 'Esta aplicación no se puede cambiar a ese tipo. Solo se pueden reetiquetar el software que el panel puede reconocer en el disco; cualquier otro reclamaría funciones que la aplicación no podría usar.',
        'only_from_generic' => 'Solo una aplicación PHP personalizada o estática puede reetiquetarse como otro tipo. Esta aplicación ya está configurada con un tipo concreto, y convertir un software en otro no es algo que pueda hacer una etiqueta.',
        'no_evidence' => 'Nada en esta aplicación parece :type. Sube primero ese software y vuelve a ejecutar Detectar: el panel solo cambia el tipo de una aplicación cuando puede ver ese software en su propio directorio.',
    ],

    'failure_reason' => [

        'download_unreachable' => 'La descarga no se realizó: el servidor no pudo llegar al host desde el que descarga. Compruebe el acceso a internet y el DNS del servidor y vuelva a intentarlo.',

        'release_not_found' => 'Se accedió a la lista de versiones, pero no hay ninguna que este servidor pueda instalar. Compruebe la versión de PHP o vuelva a intentarlo más tarde.',

        'verify_http' => 'Tras el despliegue, la aplicación respondió con un error del servidor (HTTP 5xx) en lugar de una página. Revise su registro: el registro del despliegue tiene el estado exacto.',
        'attached_database_engine_mismatch' => 'Esta aplicación ya tiene una base de datos asociada, pero funciona con un motor que esta aplicación no puede usar. Desvincúlela, o asocie una en un motor compatible, e inténtelo de nuevo.',
        'serving_error' => 'La aplicación se inició pero responde a cada solicitud con un error. Lo más probable es que sus recursos no se compilaran por completo; consulte el registro de la aplicación.',
        'not_answering' => 'La aplicación se inició pero nunca respondió a una solicitud. Consulte el registro de la aplicación para ver por qué no está escuchando.',
        'owner_not_created' => 'La aplicación se inició, pero no se pudo confirmar su administrador. La aplicación no se entregó, porque mientras no exista un administrador cualquiera que la abra puede crearlo. Inténtelo de nuevo; si sigue fallando, revise el registro de la aplicación.',
        'claim_refused' => 'La aplicación arrancó, pero rechazó el administrador que el panel intentó crear, así que la aplicación no tiene propietario. La causa habitual es la dirección de correo: Chatwoot rechaza los dominios desechables y de ejemplo, y test.com es uno de ellos — usa una dirección real. La aplicación no registra el motivo, así que en su registro no hay nada. Elimina la aplicación y vuelve a crearla con otra dirección.',
        'app_not_ready' => 'La aplicación se inició, pero no terminó de arrancar en 2 minutos, así que no se pudo crear su administrador. Revise el registro de la aplicación e inténtelo de nuevo.',
        'out_of_memory' => 'El servidor se quedó sin memoria durante este paso y el sistema lo detuvo. Libere memoria, o añada swap, e inténtelo de nuevo.',
        'no_build_tools' => 'Este paso necesitaba compilar un módulo nativo y este servidor no tiene ningún compilador instalado. Instale las herramientas de compilación desde la pantalla de configuración y vuelva a intentarlo. Elegir otra versión de Node también puede ayudar, ya que algunas incluyen binarios ya compilados, pero cada paquete decide cuáles, así que por sí solo no es una solución fiable.',
        'composer_platform' => 'Composer no pudo instalar las dependencias de esta aplicación con la versión de PHP configurada en la aplicación. La versión de PHP de la aplicación, o alguna de las extensiones que necesita, no cumple lo que exige el proyecto. Cambie la versión de PHP de la aplicación a una compatible, o instale la extensión que falta, y vuelva a desplegar.',
        'registry_auth' => 'Docker no pudo descargar esta imagen porque el registro la rechazó. O el nombre o la etiqueta de la imagen son incorrectos, o la imagen es privada — Docker informa de ambos casos igual, así que compruebe primero la referencia. Si la imagen es privada, tenga en cuenta que el panel todavía no puede iniciar sesión en un registro, por lo que solo puede ejecutar imágenes de acceso público.',
        'registry_credentials_rejected' => 'El registro rechazó la credencial con la que esta aplicación descarga la imagen. Lo más probable es que el token haya caducado o se haya revocado: renuévelo en la página de Docker y vuelva a desplegar. La referencia de la imagen es correcta; el registro respondió, simplemente no aceptó este usuario y este token.',
        'container_restarting' => 'El contenedor arranca y se detiene una y otra vez, así que la aplicación no puede servir. Su propio registro es donde mirar: normalmente un comando o entrypoint que termina de inmediato, una variable de entorno que falta, o un archivo de configuración que la imagen no pudo leer.',
        'script_git_auth' => 'Tu script de despliegue ejecuta un comando de git (normalmente git pull) que necesita iniciar sesión en el repositorio, y el script no tiene credenciales, así que falla con un repositorio privado. No lo necesitas: el panel ya descarga el código más reciente con la cuenta conectada antes de que se ejecute tu script. Quita esa línea del script de despliegue y vuelve a desplegar.',
        'script_php_missing' => 'Tu script de despliegue usa una variable {PHPxx} de una versión de PHP que no está instalada en este servidor. Instala esa versión en la pantalla de PHP o usa {php} para la versión de la propia aplicación, y vuelve a desplegar.',
        'composer_dependencies_missing' => 'Este proyecto necesita dependencias de Composer y no se instaló ninguna, por lo que la aplicación no tiene vendor/autoload.php y todas las peticiones fallarán. Añada al script de despliegue un paso que ejecute composer install y vuelva a desplegar.',
    ],

    'port_free' => 'El puerto :port está libre.',

    'rendering' => [
        'php' => 'Aplicación PHP (Laravel, Symfony, PHP simple)',
        'ssr' => 'Renderizado en servidor (ejecuta un proceso)',
        'csr' => 'Renderizado en cliente (compilado a archivos)',
        'static' => 'Aplicación estática (compilada a archivos)',
    ],

    'package_manager' => [
        'npm' => 'npm',
        'yarn' => 'Yarn',
        'pnpm' => 'pnpm',
        'bun' => 'Bun',
    ],

    'supervisor_installing' => 'Instalando supervisor, que es donde se ejecutan los workers. Tarda un momento: vuelve a crear el worker cuando termine.',

    'placeholder_page' => [
        'lede' => 'Esta aplicación está lista y funcionando. Sustituye esta página por la tuya: hasta entonces, la ve cada visitante.',
        'php_running' => 'PHP está funcionando en esta aplicación',
        'step_files_title' => 'Sube tus archivos',
        'step_files_body' => 'Usa el Administrador de archivos del panel o conéctate por SFTP con el usuario del sistema de esta aplicación.',
        'step_deploy_title' => 'O despliega desde git',
        'step_deploy_body' => 'Conecta la aplicación a un repositorio y el panel la descargará y compilará en cada push.',
        'foot' => 'Página de marcador creada por el panel de control.',
    ],

    'supervisor_mode' => [
        'systemd' => 'Unidad systemd',
        'pm2' => 'PM2 (adoptado)',
    ],

    'disabled_page' => [
        'title' => 'Aplicación no disponible',
        'heading' => 'Esta aplicación no está disponible temporalmente',
        'lede' => 'Su propietario lo ha puesto fuera de línea. Vuelve a intentarlo más tarde.',
        'foot' => 'Servido por el panel de control.',
    ],

    // A deploy that failed after its checkout left the new code live.
    // See Application::codeOnDisk().
    'code_on_disk' => [
        'incomplete' => 'El último despliegue falló después de colocar el código nuevo, así que la aplicación está ejecutando el commit :commit, que no está desplegado por completo. Corrige el problema y vuelve a desplegar.',
    ],

    // A delivery for a site whose deploy-on-push is switched off. See
    // ApplicationWebhookController::receive().
    'webhook_delivery' => [
        'disabled' => 'El despliegue al hacer push está desactivado para esta aplicación en el panel, así que no se desplegó nada. Vuelve a activarlo en el panel o elimina este webhook.',
    ],

    // Why deploy-on-push still needs the webhook added by hand. See
    // WebhookRegistrar.
    'webhook_registration' => [
        'no_account' => 'Esta aplicación se despliega desde una URL pública, no desde una cuenta de Git conectada, así que el panel no puede añadir el webhook por ti. Añádelo en la configuración del repositorio con la URL y el secreto de abajo.',
        'signing_token' => 'GitLab crea los tokens de firma por sí mismo, así que el panel no puede añadir este webhook por ti. Añádelo en la configuración de Webhooks del repositorio con la URL de abajo y tu token de firma.',
        'not_public' => 'La dirección del panel no es accesible desde internet, así que GitHub, GitLab o Bitbucket no podrían enviarle entregas. Da al panel una dirección pública o añade el webhook a mano cuando la tenga.',
        'provider_refused' => 'El proveedor de Git no permitió que el panel añadiera el webhook. Probablemente el token conectado no tiene permiso para gestionar webhooks en este repositorio. Añádelo a mano con la URL y el secreto de abajo, o vuelve a conectar la cuenta con ese permiso.',
        'removal_refused' => 'El despliegue al hacer push está desactivado, pero el proveedor de Git no permitió que el panel eliminara el webhook que añadió. Probablemente el token conectado no tiene permiso para borrar webhooks. Los push se seguirán enviando y rechazando hasta que borres el webhook en la configuración del repositorio.',
    ],

    // OLD-20: what became of the site's system user when asked to remove it.
    'system_user_removal' => [
        'removed' => 'También se eliminó el usuario del sistema :username.',
        'still_used' => 'Se conservó el usuario del sistema :username: todavía es dueño de otras aplicaciones.',
        'has_processes' => 'Se conservó el usuario del sistema :username: sigue conectado o ejecutando algo. Elimínelo en Usuarios del sistema cuando termine.',
        'failed' => 'No se pudo eliminar el usuario del sistema :username. Elimínelo en Usuarios del sistema.',
    ],
];
