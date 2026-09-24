<?php

return [
    'compose_port_ambiguous' => 'Este archivo compose publica más de un puerto, así que el panel no puede saber cuál sirve el sitio. Indica en «Puerto del contenedor» el puerto interno que debe usarse como proxy.',
    'compose_unparsable' => 'Docker no pudo leer este archivo compose. Revisa la indentación y las comillas; el error de Docker está en el registro de operaciones del servidor.',
    'compose_no_services' => 'Este archivo compose no define ningún servicio, así que no habría nada que ejecutar.',
    'compose_bind_outside' => 'El servicio :service monta :path, que está fuera del directorio propio de esta aplicación. Un contenedor solo puede montar sus propios archivos.',
    'compose_port_public' => 'Este archivo compose publica un puerto en todas las direcciones con un formato que el panel no pudo reescribir. Las reglas de firewall de Docker van por delante de las del panel, así que sería accesible desde internet aunque la página de Firewall lo muestre cerrado. Publícalo en 127.0.0.1 —por ejemplo `"127.0.0.1:3001:3001"`— y nginx hará de proxy.',
    'compose_forbidden' => [
        'privileged' => 'El servicio :service se ejecuta en modo privilegiado, lo que le da todo el host.',
        'cap_add' => 'El servicio :service añade capacidades de Linux. SYS_ADMIN por sí sola basta para montar los sistemas de archivos del host.',
        'devices' => 'El servicio :service mapea un dispositivo del host. Un dispositivo de bloque en bruto es cada archivo de ese disco.',
        'namespace' => 'El servicio :service comparte uno de los espacios de nombres del host, lo que le permite ver y señalizar procesos fuera del contenedor.',
        'security_opt' => 'El servicio :service establece opciones de seguridad. Ahí es donde se desactivan AppArmor y seccomp.',
        'network_mode' => 'El servicio :service establece un modo de red. Eso lo pondría en la red del host, evitando la publicación en loopback y el firewall.',
        'cgroup_parent' => 'El servicio :service establece un cgroup padre, lo que evade los límites de recursos que aplica este servidor.',
    ],
    'database_engine_not_used' => 'Esta aplicación no usa una base de datos.',
    'database_engine_unsupported' => 'Esta aplicación no puede usar ese motor de base de datos. :application admite uno diferente.',
    'database_engine_unavailable' => 'Ese motor de base de datos no se está ejecutando en este servidor. Instálalo o inícialo primero.',
    'database_engine_too_old' => 'El :engine de este servidor es demasiado antiguo para :application, que necesita :minimum o posterior. Actualízalo o elige otro motor de base de datos.',

    // Deleting a site can take its databases with it (`remove_databases`).
    // The first refusal is the caller lacking `database` manage; the second
    // is the honest half-success — the site went, a database did not.
    'database_removal_not_permitted' => 'Puedes eliminar este sitio, pero no sus bases de datos. Pide acceso a bases de datos a un administrador o elimina el sitio sin borrarlas.',
    'databases_not_removed' => 'El sitio se eliminó, pero estas bases de datos siguen en el servidor: :databases. Elimínalas desde la pantalla de bases de datos o indica la referencia al soporte.',

    'primary_domain_not_removable' => 'No se puede eliminar el dominio principal. Haga principal otro dominio primero.',
    'primary_domain_not_editable' => 'Un dominio principal no se puede editar. Haz principal otro dominio primero.',
    'domain_taken' => 'Este dominio ya está en uso en este servidor.',
    'domain_taken_by' => 'Este dominio ya lo usa la aplicación «:application».',
    'unsupported_web_server' => 'El panel no puede escribir la configuración del sitio para :web_server.',
    'no_web_server' => 'ningún servidor web detectado',
    'provision_failed' => 'La configuración del sitio falló en el paso «:step».',
    'not_a_git_application' => 'La aplicación no es un despliegue de git, así que no hay nada que descargar.',
    'no_database_engine' => 'No hay ningún motor de base de datos disponible. Instala y configura MySQL o MariaDB antes de crear esta aplicación.',
    'no_process' => '\":name\" no ejecuta un proceso propio.',
    'process_failed' => 'No se pudo :action la aplicación. Indica la referencia al soporte.',
    'system_user_missing' => 'Falta el usuario del sistema de :name, así que el panel no puede trabajar con los archivos de esta aplicación. Aún puedes eliminar la aplicación.',
    'no_port_available' => 'No hay puertos libres entre :from y :to. Libera uno o amplía el rango.',

    'webhook_not_a_git_application' => 'El despliegue automático solo está disponible para aplicaciones desplegadas desde un repositorio git.',

    'already_disabled' => 'Esta aplicación ya está deshabilitada.',
    'not_disabled' => 'Esta aplicación no está deshabilitada.',
    'availability_failed' => 'No se pudo cambiar la disponibilidad de la aplicación en el servidor.',
    'basic_auth_failed' => 'No se pudo cambiar la protección con contraseña en el servidor.',
    'environment_failed' => 'El panel no pudo comprobar el archivo de entorno de la aplicación en el servidor, así que no informará de nada en un sentido ni en otro.',
    'bot_blocker_failed' => 'No se pudo cambiar la política del bloqueador de bots de IA en el servidor.',
    'bot_agent_invalid' => 'Introduce un único nombre de bot, como GPTBot o SemrushBot: solo letras, números, puntos y guiones.',
    'bot_agent_too_broad' => 'Eso es demasiado general: también bloquearía buscadores como Google y Bing. Usa el nombre completo del bot.',
    'bot_agent_search_engine' => 'Eso es un buscador, no un rastreador de IA. Bloquearlo eliminaría tu sitio de los resultados de búsqueda.',
    'web_root_failed' => 'No se pudo cambiar la raíz web en el servidor.',
    'web_root_not_found' => 'No se encontró el directorio raíz web en el servidor. Revisa la raíz web en la configuración de la aplicación y vuelve a aprovisionarla si nunca se creó.',
    'waf_unsupported' => 'El Firewall 8G aún no está disponible en :server.',
    'waf_failed' => 'No se pudo cambiar la configuración del firewall en el servidor.',
    'staging_failed' => 'La operación de staging falló en el servidor.',
    'staging_rollback_failed' => 'La publicación desde staging falló y no se pudo restaurar producción. El sitio permanece deshabilitado. Indica la referencia al soporte.',
    'clone_failed' => 'La operación de clonación falló en el servidor.',
    'fail2ban_failed' => 'La operación de fail2ban falló en el servidor.',

    'permissions_fix_failed' => 'No se pudieron restablecer los permisos de archivo en el servidor.',

    'unsafe_path' => 'Esa ruta no está permitida.',
    'file_too_large' => 'Ese archivo es demasiado grande para abrirlo en el editor. Descárgalo: las descargas no tienen límite de tamaño.',
    'file_not_text' => 'Ese archivo no parece texto y no se puede abrir aquí.',
    'file_not_previewable' => 'Ese archivo no es una imagen, así que no hay nada que mostrar. Descárgalo para abrirlo en tu equipo.',
    'file_svg_not_previewable' => 'Los archivos SVG no se muestran aquí, porque un SVG puede contener código. Descárgalo para verlo.',
    'file_too_large_to_preview' => 'Esa imagen es demasiado grande para mostrarla aquí. Descárgala: las descargas no tienen límite de tamaño.',

    'archive_failed' => [
        'timed_out' => 'El archivo comprimido tardó más de lo que permite el servidor y se detuvo. Prueba con una selección más pequeña.',
        'command_failed' => 'El servidor no pudo terminar el archivo comprimido. No se dejó nada a medio escribir.',
        'application_missing' => 'El sitio se eliminó antes de poder crear el archivo comprimido.',
        'worker' => 'El proceso se detuvo inesperadamente en el servidor y no terminó.',
        'unknown' => 'El archivo comprimido no se completó.',
    ],
    'file_operation_failed' => 'La operación de archivo falló en el servidor.',

    'file_not_archive' => 'Aquí solo se pueden extraer archivos .zip y .tar.gz.',
    'archive_unreadable' => 'No se pudo leer ese archivo. Puede estar dañado.',
    'archive_empty' => 'Ese archivo no contiene nada.',
    'archive_too_many_entries' => 'Ese archivo tiene demasiados archivos para extraerlo aquí.',
    'archive_too_large' => 'Ese archivo sería demasiado grande una vez extraído.',
    'archive_has_symlink' => 'Ese archivo contiene un enlace simbólico, lo cual no está permitido.',
    'archive_unsafe_entry' => 'Ese archivo contiene una ruta de archivo que no está permitida.',

    'upload_exists' => '«:name» ya existe aquí. Elimínalo primero si quieres reemplazarlo: una subida no sobrescribe un archivo.',

    'path_exists' => 'Ya existe algo en esa ruta.',
    'cannot_delete_root' => 'No se puede eliminar la carpeta raíz del sitio.',
    'target_not_archive' => 'El nombre del nuevo archivo comprimido debe terminar en .zip, .tar.gz o .tgz.',
    'unknown_backup' => 'Esa no es una copia de seguridad conocida de este archivo.',

    'upload_directory_missing' => 'La carpeta de destino de esta subida ya no existe.',
    'upload_insufficient_space' => 'El servidor no tiene suficiente espacio libre en disco para esta subida.',

    'bulk_count_mismatch' => 'El número que confirmaste no coincide con la cantidad de elementos seleccionados.',
    'sources_not_in_one_directory' => 'Todos los elementos que se van a comprimir deben estar en la misma carpeta.',
    'release_failed' => 'No se pudo crear el directorio del sitio en el servidor.',
    'supervisor_missing' => 'Los workers necesitan supervisord, que no está instalado en este servidor. Instálalo con `apt-get install supervisor` y vuelve a crear el worker.',
    'supervisor_already_installed' => 'Supervisor ya está instalado en este servidor.',
    'worker_control_failed' => 'No se pudo controlar el worker en el servidor.',

    // Which system account a new site runs as. Generating one creates a
    // real Linux account, which is why it needs its own permission.
    'generate_system_user_forbidden' => 'No tiene permiso para crear usuarios del sistema, así que no se puede generar uno nuevo para este sitio. Elija un usuario del sistema existente.',
    'system_user_conflict' => 'Elija un usuario del sistema nuevo o uno existente, no ambos.',
    'system_user_name_unavailable' => 'No se pudo reservar un nombre de usuario del sistema para este sitio: no se pudo consultar al servidor qué nombres ya están en uso. Inténtelo de nuevo o elija un usuario del sistema existente.',
];
