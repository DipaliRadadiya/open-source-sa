<?php

return [
    'primary_domain_not_removable' => 'No se puede eliminar el dominio principal. Haga principal otro dominio primero.',
    'unsupported_web_server' => 'El panel no puede escribir la configuración del sitio para :web_server.',
    'no_web_server' => 'ningún servidor web detectado',
    'provision_failed' => 'La configuración del sitio falló en el paso «:step».',
    'not_a_git_application' => 'La aplicación no es un despliegue de git, así que no hay nada que descargar.',
    'no_database_engine' => 'No hay ningún motor de base de datos disponible. Instala y configura MySQL o MariaDB antes de crear esta aplicación.',
    'no_process' => '\":name\" no ejecuta un proceso propio.',
    'process_failed' => 'No se pudo :action la aplicación. Indica la referencia al soporte.',
    'no_port_available' => 'No hay puertos libres entre :from y :to. Libera uno o amplía el rango.',

    'webhook_not_a_git_application' => 'El despliegue automático solo está disponible para aplicaciones desplegadas desde un repositorio git.',

    'already_disabled' => 'Esta aplicación ya está deshabilitada.',
    'not_disabled' => 'Esta aplicación no está deshabilitada.',
    'availability_failed' => 'No se pudo cambiar la disponibilidad de la aplicación en el servidor.',
    'basic_auth_failed' => 'No se pudo cambiar la protección con contraseña en el servidor.',
    'bot_blocker_failed' => 'No se pudo cambiar la política del bloqueador de bots de IA en el servidor.',
    'bot_agent_invalid' => 'Introduce un único nombre de bot, como GPTBot o SemrushBot: solo letras, números, puntos y guiones.',
    'bot_agent_too_broad' => 'Eso es demasiado general: también bloquearía buscadores como Google y Bing. Usa el nombre completo del bot.',
    'bot_agent_search_engine' => 'Eso es un buscador, no un rastreador de IA. Bloquearlo eliminaría tu sitio de los resultados de búsqueda.',
    'web_root_failed' => 'No se pudo cambiar la raíz web en el servidor.',
    'web_root_not_found' => 'No se encontró el directorio raíz web en el servidor. Revisa la raíz web en la configuración de la aplicación y vuelve a aprovisionarla si nunca se creó.',
    'waf_unsupported' => 'El Firewall 8G aún no está disponible en :server.',
    'waf_failed' => 'No se pudo cambiar la configuración del firewall en el servidor.',
    'staging_failed' => 'La operación de staging falló en el servidor.',
    'clone_failed' => 'La operación de clonación falló en el servidor.',
    'fail2ban_failed' => 'La operación de fail2ban falló en el servidor.',

    'permissions_fix_failed' => 'No se pudieron restablecer los permisos de archivo en el servidor.',

    'unsafe_path' => 'Esa ruta no está permitida.',
    'file_too_large' => 'Ese archivo es demasiado grande para abrirlo en el editor. Descárgalo: las descargas no tienen límite de tamaño.',
    'file_not_text' => 'Ese archivo no parece texto y no se puede abrir aquí.',
    'file_operation_failed' => 'La operación de archivo falló en el servidor.',

    'file_not_archive' => 'Aquí solo se pueden extraer archivos .zip y .tar.gz.',
    'archive_unreadable' => 'No se pudo leer ese archivo. Puede estar dañado.',
    'archive_empty' => 'Ese archivo no contiene nada.',
    'archive_too_many_entries' => 'Ese archivo tiene demasiados archivos para extraerlo aquí.',
    'archive_too_large' => 'Ese archivo sería demasiado grande una vez extraído.',
    'archive_has_symlink' => 'Ese archivo contiene un enlace simbólico, lo cual no está permitido.',
    'archive_unsafe_entry' => 'Ese archivo contiene una ruta de archivo que no está permitida.',

    'path_exists' => 'Ya existe algo en esa ruta.',
    'cannot_delete_root' => 'No se puede eliminar la carpeta raíz del sitio.',
    'target_not_zip' => 'El nombre del nuevo archivo debe terminar en .zip.',
    'unknown_backup' => 'Esa no es una copia de seguridad conocida de este archivo.',

    'upload_directory_missing' => 'La carpeta de destino de esta subida ya no existe.',
    'upload_insufficient_space' => 'El servidor no tiene suficiente espacio libre en disco para esta subida.',

    'bulk_count_mismatch' => 'El número que confirmaste no coincide con la cantidad de elementos seleccionados.',
    'sources_not_in_one_directory' => 'Todos los elementos que se van a comprimir deben estar en la misma carpeta.',
];
