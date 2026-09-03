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
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Creador de blogs y sitios web'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Gestione sus bases de datos en el navegador'],
        'uptimekuma' => ['title' => 'Uptime Kuma', 'tagline' => 'Monitorización de disponibilidad y páginas de estado'],
        'n8n' => ['title' => 'n8n', 'tagline' => 'Automatización de flujos de trabajo (licencia fair-code)'],
        'nodered' => ['title' => 'Node-RED', 'tagline' => 'Conecta dispositivos, APIs y servicios'],
        'nodebb' => ['title' => 'NodeBB', 'tagline' => 'Software de foros — necesita MongoDB'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Sincronización y uso compartido de archivos privados'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'Sistema de gestión de contenidos flexible'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'Cursos y aprendizaje en línea'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'Automatización de marketing y campañas'],
        'craftcms' => ['title' => 'Craft CMS', 'tagline' => 'Gestión de contenidos para desarrolladores'],
        'akaunting' => ['title' => 'Akaunting', 'tagline' => 'Contabilidad y facturación'],
        'statamic' => ['title' => 'Statamic', 'tagline' => 'CMS de archivos planos, sin base de datos'],
        'prestashop' => ['title' => 'PrestaShop', 'tagline' => 'Tienda en línea y comercio electrónico'],
        'git' => ['title' => 'Desde un repositorio Git', 'tagline' => 'Despliega tu propio código desde GitHub, GitLab o Bitbucket'],
        'php' => ['title' => 'Sitio PHP vacío', 'tagline' => 'Un sitio vacío: sube tus propios archivos'],
        'static' => ['title' => 'Sitio estático', 'tagline' => 'HTML, CSS y JavaScript simples'],
    ],

    'status' => [
        'pending' => 'Aún no desplegado',
        'provisioning' => 'Configurando…',
        'active' => 'En ejecución',
        'failed' => 'Error de configuración',
    ],

    'unavailable' => [
        'database' => 'Esta aplicación necesita :engines, que este servidor no tiene.',
        'php' => 'Este servidor no tiene PHP instalado.',
        'node' => 'Este servidor no tiene Node.js instalado.',
        'web_server' => 'Esta aplicación aún no está disponible en servidores :web_server.',
    ],

    'git_source' => [
        'account' => 'Desde una cuenta conectada',
        'public_url' => 'Pegar la URL de un repositorio público',
    ],

    'fields' => [
        'company_name' => 'Nombre de la empresa',
        'company_email' => 'Correo de la empresa',
        'locale' => 'Configuración regional',
        'site_name' => 'Nombre del sitio',
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
        'site_title' => 'Título del sitio',
        'admin_user' => 'Usuario administrador',
        'admin_username' => 'Usuario administrador',
        'admin_email' => 'Correo del administrador',
        'admin_password' => 'Contraseña del administrador',
        'site_language' => 'Idioma del sitio',
        'table_prefix' => 'Prefijo de tablas',
        'mailer_name' => 'Nombre del remitente',
        'mailer_email' => 'Dirección del remitente',
        'mailer_host' => 'Servidor SMTP',
        'mailer_port' => 'Puerto SMTP',
        'mailer_username' => 'Usuario SMTP',
        'mailer_password' => 'Contraseña SMTP',
    ],

    'help' => [
        'start_command' => 'El archivo de entrada, por ejemplo \"node server.js\". No \"npm start\": un gestor de paquetes bifurca el proceso real, así que las señales de apagado nunca le llegan.',
        'app_port' => 'Si lo dejas vacío, el panel elige uno libre.',
        'rendering_type' => 'El renderizado en servidor ejecuta tu app y hace de proxy hacia ella. Los otros dos compilan a archivos que el servidor web entrega directamente: más rápido y sin nada que mantener en ejecución.',
        'repository_url' => 'Un repositorio público: no hace falta cuenta. Debe ser una dirección https://.',
        'build_command' => 'Se ejecuta tras descargar el código, p. ej. composer install --no-dev',
        'deploy_script' => 'Se ejecuta tras descargar el código, como tu usuario del sitio. Déjalo vacío para usar el comando de compilación.',
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
        'check_account' => 'Comprobando la cuenta del sistema',
        'create_directory' => 'Creando el directorio',
        'set_ownership' => 'Estableciendo la propiedad',
        'placeholder' => 'Añadiendo una página de marcador',
        'write_config' => 'Escribiendo la configuración del sitio',
        'test_config' => 'Probando la configuración',
        'reload' => 'Recargando el servidor web',
        'start_app' => 'Iniciando la aplicación',
        'write_unit' => 'Preparando el servicio',
        'restart_app' => 'Reiniciando la aplicación',
        'harden' => 'Aplicando ajustes de seguridad',
        'trust_domain' => 'Autorizando el dominio',
        'set_password' => 'Estableciendo la contraseña de administrador',
        'verify_serving' => 'Comprobando que el sitio responde',
        'worker' => 'El proceso en segundo plano se detuvo',
    ],
    /*
    | Why provisioning failed, keyed by the `failed_reason` code on the
    | application. Only set where the exit status genuinely identifies
    | the cause; most failures carry the step and reference instead.
    */
    'failure_reason' => [
        'serving_error' => 'La aplicación se inició pero responde a cada solicitud con un error. Lo más probable es que sus recursos no se compilaran por completo; consulte el registro de la aplicación.',
        'not_answering' => 'La aplicación se inició pero nunca respondió a una solicitud. Consulte el registro de la aplicación para ver por qué no está escuchando.',
        'out_of_memory' => 'El servidor se quedó sin memoria durante este paso y el sistema lo detuvo. Libere memoria, o añada swap, e inténtelo de nuevo.',
    ],

    'port_free' => 'El puerto :port está libre.',

    'rendering' => [
        'php' => 'Aplicación PHP (Laravel, Symfony, PHP simple)',
        'ssr' => 'Renderizado en servidor (ejecuta un proceso)',
        'csr' => 'Renderizado en cliente (compilado a archivos)',
        'static' => 'Sitio estático (compilado a archivos)',
    ],

    'package_manager' => [
        'npm' => 'npm',
        'yarn' => 'Yarn',
        'pnpm' => 'pnpm',
        'bun' => 'Bun',
    ],

];
