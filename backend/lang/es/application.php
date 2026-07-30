<?php

return [
    'types' => [
        'wordpress' => ['title' => 'WordPress', 'tagline' => 'Creador de blogs y sitios web'],
        'phpmyadmin' => ['title' => 'phpMyAdmin', 'tagline' => 'Gestione sus bases de datos en el navegador'],
        'nextcloud' => ['title' => 'Nextcloud', 'tagline' => 'Sincronización y uso compartido de archivos privados'],
        'joomla' => ['title' => 'Joomla', 'tagline' => 'Sistema de gestión de contenidos flexible'],
        'moodle' => ['title' => 'Moodle', 'tagline' => 'Cursos y aprendizaje en línea'],
        'mautic' => ['title' => 'Mautic', 'tagline' => 'Automatización de marketing y campañas'],
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
        'php' => 'Este servidor no tiene PHP instalado.',
        'node' => 'Este servidor no tiene Node.js instalado.',
    ],

    'git_source' => [
        'account' => 'Desde una cuenta conectada',
        'public_url' => 'Pegar la URL de un repositorio público',
    ],

    'fields' => [
        'name' => 'Nombre',
        'domain' => 'Dominio',
        'system_user_id' => 'Usuario del sistema',
        'php_version' => 'Versión de PHP',
        'node_version' => 'Versión de Node.js',
        'app_port' => 'Puerto de la aplicación',
        'web_root' => 'Raíz web',
        'build_command' => 'Comando de compilación',
        'start_command' => 'Comando de inicio',
        'git_source' => 'Origen',
        'git_account_id' => 'Cuenta de Git',
        'repository' => 'Repositorio',
        'repository_url' => 'URL del repositorio',
        'branch' => 'Rama',
        'site_title' => 'Título del sitio',
        'admin_user' => 'Usuario administrador',
        'admin_email' => 'Correo del administrador',
        'admin_password' => 'Contraseña del administrador',
        'site_language' => 'Idioma del sitio',
        'table_prefix' => 'Prefijo de tablas',
    ],

    'help' => [
        'repository_url' => 'Un repositorio público: no hace falta cuenta. Debe ser una dirección https://.',
        'build_command' => 'Se ejecuta tras descargar el código, p. ej. composer install --no-dev',
    ],

    'steps' => [
        'create_database' => 'Creando la base de datos',
        'download' => 'Descargando la aplicación',
        'extract' => 'Descomprimiendo los archivos',
        'configure' => 'Escribiendo la configuración',
        'install_cli' => 'Instalando la herramienta de instalación',
        'install_app' => 'Ejecutando el instalador',
        'clone' => 'Clonando el repositorio',
        'fetch' => 'Descargando el código más reciente',
        'checkout' => 'Cambiando a la rama',
        'build' => 'Ejecutando el comando de compilación',
        'write_credential' => 'Preparando el acceso a git',
        'create_directory' => 'Creando el directorio',
        'set_ownership' => 'Estableciendo la propiedad',
        'placeholder' => 'Añadiendo una página de marcador',
        'write_config' => 'Escribiendo la configuración del sitio',
        'test_config' => 'Probando la configuración',
        'reload' => 'Recargando el servidor web',
        'worker' => 'El proceso en segundo plano se detuvo',
    ],
];
