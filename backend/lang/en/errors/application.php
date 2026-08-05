<?php

return [
    'primary_domain_not_removable' => 'A primary domain cannot be removed. Make another domain primary first.',
    'unsupported_web_server' => 'The panel cannot write site configuration for :web_server.',
    'no_web_server' => 'no detected web server',
    'provision_failed' => 'Setting up the site failed at the ":step" step.',
    'not_a_git_application' => 'The application is not a git deployment, so there is nothing to fetch.',
    'no_database_engine' => 'No database engine is available. Install and configure MySQL or MariaDB before creating this application.',
    'no_process' => '\":name\" does not run a process of its own.',
    'process_failed' => 'Could not :action the application. Quote the reference to support.',
    'no_port_available' => 'No free port between :from and :to. Free one or widen the range.',

    'webhook_not_a_git_application' => 'Deploy-on-push is only available for applications deployed from a git repository.',

    'permissions_fix_failed' => 'Resetting file permissions failed on the server.',

    'unsafe_path' => 'That path is not allowed.',
    'file_too_large' => 'That file is too large to open here. Use SFTP for large files.',
    'file_not_text' => 'That file does not look like text and cannot be opened here.',
    'file_operation_failed' => 'The file operation failed on the server.',

];
