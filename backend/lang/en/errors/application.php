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

    'already_disabled' => 'This application is already disabled.',
    'not_disabled' => 'This application is not disabled.',
    'availability_failed' => 'Changing the application\'s availability failed on the server.',
    'basic_auth_failed' => 'Changing password protection failed on the server.',
    'bot_blocker_failed' => 'Changing the AI Bot Blocker policy failed on the server.',
    'bot_agent_invalid' => 'Enter a single bot name, like GPTBot or SemrushBot — letters, numbers, dots and dashes only.',
    'bot_agent_too_broad' => 'That is too general — it would also block search engines like Google and Bing. Use the bot\'s full name.',
    'bot_agent_search_engine' => 'That is a search engine, not an AI crawler. Blocking it would remove your site from search results.',
    'web_root_failed' => 'Changing the web root failed on the server.',
    'web_root_not_found' => 'The web root directory could not be found on the server. Check the web root in the application settings, and re-provision the application if it was never created.',
    'waf_failed' => 'Changing the firewall settings failed on the server.',
    'staging_failed' => 'The staging operation failed on the server.',
    'clone_failed' => 'The clone operation failed on the server.',
    'fail2ban_failed' => 'The fail2ban operation failed on the server.',

    'permissions_fix_failed' => 'Resetting file permissions failed on the server.',

    'unsafe_path' => 'That path is not allowed.',
    'file_too_large' => 'That file is too large to open here. Use SFTP for large files.',
    'file_not_text' => 'That file does not look like text and cannot be opened here.',
    'file_operation_failed' => 'The file operation failed on the server.',

    'file_not_archive' => 'Only .zip and .tar.gz archives can be extracted here.',
    'archive_unreadable' => 'That archive could not be read. It may be corrupt.',
    'archive_empty' => 'That archive has nothing in it.',
    'archive_too_many_entries' => 'That archive has too many files to extract here.',
    'archive_too_large' => 'That archive would be too large once extracted.',
    'archive_has_symlink' => 'That archive contains a symbolic link, which is not allowed.',
    'archive_unsafe_entry' => 'That archive contains a file path that is not allowed.',

    'path_exists' => 'Something already exists at that path.',
    'cannot_delete_root' => 'The site\'s own root folder cannot be deleted.',
    'target_not_zip' => 'The new archive\'s name must end in .zip.',
    'unknown_backup' => 'That is not a known backup of this file.',

    'upload_directory_missing' => 'The folder this upload was going to no longer exists.',
    'upload_insufficient_space' => 'The server does not have enough free disk space for this upload.',

];
