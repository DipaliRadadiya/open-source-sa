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

    'accepted' => 'The :attribute field must be accepted.',
    'accepted_if' => 'The :attribute field must be accepted when :other is :value.',
    'active_url' => 'The :attribute field must be a valid URL.',
    'after' => 'The :attribute field must be a date after :date.',
    'after_or_equal' => 'The :attribute field must be a date after or equal to :date.',
    'alpha' => 'The :attribute field must only contain letters.',
    'alpha_dash' => 'The :attribute field must only contain letters, numbers, dashes, and underscores.',
    'alpha_num' => 'The :attribute field must only contain letters and numbers.',
    'any_of' => 'The :attribute field is invalid.',
    'array' => 'The :attribute field must be an array.',
    'ascii' => 'The :attribute field must only contain single-byte alphanumeric characters and symbols.',
    'base64' => 'The :attribute field must be a valid Base64 string.',
    'before' => 'The :attribute field must be a date before :date.',
    'before_or_equal' => 'The :attribute field must be a date before or equal to :date.',
    'between' => [
        'array' => 'The :attribute field must have between :min and :max items.',
        'file' => 'The :attribute field must be between :min and :max kilobytes.',
        'numeric' => 'The :attribute field must be between :min and :max.',
        'string' => 'The :attribute field must be between :min and :max characters.',
    ],
    'boolean' => 'The :attribute field must be true or false.',
    'can' => 'The :attribute field contains an unauthorized value.',
    'confirmed' => 'The :attribute field confirmation does not match.',
    'contains' => 'The :attribute field is missing a required value.',
    'current_password' => 'The password is incorrect.',
    'date' => 'The :attribute field must be a valid date.',
    'date_equals' => 'The :attribute field must be a date equal to :date.',
    'date_format' => 'The :attribute field must match the format :format.',
    'decimal' => 'The :attribute field must have :decimal decimal places.',
    'declined' => 'The :attribute field must be declined.',
    'declined_if' => 'The :attribute field must be declined when :other is :value.',
    'different' => 'The :attribute field and :other must be different.',
    'digits' => 'The :attribute field must be :digits digits.',
    'digits_between' => 'The :attribute field must be between :min and :max digits.',
    'dimensions' => 'The :attribute field has invalid image dimensions.',
    'distinct' => 'The :attribute field has a duplicate value.',
    'doesnt_contain' => 'The :attribute field must not contain any of the following: :values.',
    'doesnt_end_with' => 'The :attribute field must not end with one of the following: :values.',
    'doesnt_start_with' => 'The :attribute field must not start with one of the following: :values.',
    'email' => 'The :attribute field must be a valid email address.',
    'encoding' => 'The :attribute field must be encoded in :encoding.',
    'ends_with' => 'The :attribute field must end with one of the following: :values.',
    'enum' => 'The selected :attribute is invalid.',
    'exists' => 'The selected :attribute is invalid.',
    'extensions' => 'The :attribute field must have one of the following extensions: :values.',
    'file' => 'The :attribute field must be a file.',
    'filled' => 'The :attribute field must have a value.',
    'gt' => [
        'array' => 'The :attribute field must have more than :value items.',
        'file' => 'The :attribute field must be greater than :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than :value.',
        'string' => 'The :attribute field must be greater than :value characters.',
    ],
    'gte' => [
        'array' => 'The :attribute field must have :value items or more.',
        'file' => 'The :attribute field must be greater than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be greater than or equal to :value.',
        'string' => 'The :attribute field must be greater than or equal to :value characters.',
    ],
    'hex_color' => 'The :attribute field must be a valid hexadecimal color.',
    'image' => 'The :attribute field must be an image.',
    'in' => 'The selected :attribute is invalid.',
    'in_array' => 'The :attribute field must exist in :other.',
    'in_array_keys' => 'The :attribute field must contain at least one of the following keys: :values.',
    'integer' => 'The :attribute field must be an integer.',
    'ip' => 'The :attribute field must be a valid IP address.',
    'ipv4' => 'The :attribute field must be a valid IPv4 address.',
    'ipv6' => 'The :attribute field must be a valid IPv6 address.',
    'json' => 'The :attribute field must be a valid JSON string.',
    'list' => 'The :attribute field must be a list.',
    'lowercase' => 'The :attribute field must be lowercase.',
    'lt' => [
        'array' => 'The :attribute field must have less than :value items.',
        'file' => 'The :attribute field must be less than :value kilobytes.',
        'numeric' => 'The :attribute field must be less than :value.',
        'string' => 'The :attribute field must be less than :value characters.',
    ],
    'lte' => [
        'array' => 'The :attribute field must not have more than :value items.',
        'file' => 'The :attribute field must be less than or equal to :value kilobytes.',
        'numeric' => 'The :attribute field must be less than or equal to :value.',
        'string' => 'The :attribute field must be less than or equal to :value characters.',
    ],
    'mac_address' => 'The :attribute field must be a valid MAC address.',
    'max' => [
        'array' => 'The :attribute field must not have more than :max items.',
        'file' => 'The :attribute field must not be greater than :max kilobytes.',
        'numeric' => 'The :attribute field must not be greater than :max.',
        'string' => 'The :attribute field must not be greater than :max characters.',
    ],
    'max_digits' => 'The :attribute field must not have more than :max digits.',
    'mimes' => 'The :attribute field must be a file of type: :values.',
    'mimetypes' => 'The :attribute field must be a file of type: :values.',
    'min' => [
        'array' => 'The :attribute field must have at least :min items.',
        'file' => 'The :attribute field must be at least :min kilobytes.',
        'numeric' => 'The :attribute field must be at least :min.',
        'string' => 'The :attribute field must be at least :min characters.',
    ],
    'min_digits' => 'The :attribute field must have at least :min digits.',
    'missing' => 'The :attribute field must be missing.',
    'missing_if' => 'The :attribute field must be missing when :other is :value.',
    'missing_unless' => 'The :attribute field must be missing unless :other is :value.',
    'missing_with' => 'The :attribute field must be missing when :values is present.',
    'missing_with_all' => 'The :attribute field must be missing when :values are present.',
    'multiple_of' => 'The :attribute field must be a multiple of :value.',
    'not_in' => 'The selected :attribute is invalid.',
    'not_regex' => 'The :attribute field format is invalid.',
    'numeric' => 'The :attribute field must be a number.',
    'password' => [
        'letters' => 'The :attribute field must contain at least one letter.',
        'mixed' => 'The :attribute field must contain at least one uppercase and one lowercase letter.',
        'numbers' => 'The :attribute field must contain at least one number.',
        'symbols' => 'The :attribute field must contain at least one symbol.',
        'uncompromised' => 'The given :attribute has appeared in a data leak. Please choose a different :attribute.',
    ],
    'present' => 'The :attribute field must be present.',
    'present_if' => 'The :attribute field must be present when :other is :value.',
    'present_unless' => 'The :attribute field must be present unless :other is :value.',
    'present_with' => 'The :attribute field must be present when :values is present.',
    'present_with_all' => 'The :attribute field must be present when :values are present.',
    'prohibited' => 'The :attribute field is prohibited.',
    'prohibited_if' => 'The :attribute field is prohibited when :other is :value.',
    'prohibited_if_accepted' => 'The :attribute field is prohibited when :other is accepted.',
    'prohibited_if_declined' => 'The :attribute field is prohibited when :other is declined.',
    'prohibited_unless' => 'The :attribute field is prohibited unless :other is in :values.',
    'prohibits' => 'The :attribute field prohibits :other from being present.',
    'regex' => 'The :attribute field format is invalid.',
    'required' => 'The :attribute field is required.',
    'required_array_keys' => 'The :attribute field must contain entries for: :values.',
    'required_if' => 'The :attribute field is required when :other is :value.',
    'required_if_accepted' => 'The :attribute field is required when :other is accepted.',
    'required_if_declined' => 'The :attribute field is required when :other is declined.',
    'required_unless' => 'The :attribute field is required unless :other is in :values.',
    'required_with' => 'The :attribute field is required when :values is present.',
    'required_with_all' => 'The :attribute field is required when :values are present.',
    'required_without' => 'The :attribute field is required when :values is not present.',
    'required_without_all' => 'The :attribute field is required when none of :values are present.',
    'same' => 'The :attribute field must match :other.',
    'size' => [
        'array' => 'The :attribute field must contain :size items.',
        'file' => 'The :attribute field must be :size kilobytes.',
        'numeric' => 'The :attribute field must be :size.',
        'string' => 'The :attribute field must be :size characters.',
    ],
    'starts_with' => 'The :attribute field must start with one of the following: :values.',
    'string' => 'The :attribute field must be a string.',
    'timezone' => 'The :attribute field must be a valid timezone.',
    'unique' => 'The :attribute has already been taken.',
    'uploaded' => 'The :attribute failed to upload.',
    'uppercase' => 'The :attribute field must be uppercase.',
    'url' => 'The :attribute field must be a valid URL.',
    'ulid' => 'The :attribute field must be a valid ULID.',
    'uuid' => 'The :attribute field must be a valid UUID.',

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Language Lines
    |--------------------------------------------------------------------------
    |
    | Here you may specify custom validation messages for attributes using the
    | convention "attribute.rule" to name the lines. This makes it quick to
    | specify a specific custom language line for a given attribute rule.
    |
    */

    'custom' => [

        // Docker's own bounds, moved from the daemon to the form. `compose up`
        // refuses an over-provisioned quota — it does not clamp it — so without
        // these the save lands as a provisioning failure with Docker's sentence
        // in it, while the panel shows a limit the container does not have.
        'cpu_limit' => [
            'format' => 'Use a number of CPUs, with up to two decimal places — 1, 1.5, 0.5.',
            'positive' => 'The smallest CPU limit Docker accepts is :minimum. Leave the field empty for no limit.',
            'too_many' => 'This server has :cores CPUs, and Docker will not start a container asking for more. Choose :cores or less.',
        ],

        'memory_limit' => [
            'format' => 'Use a size with a unit — 512m or 2g. A number on its own means bytes to Docker, not megabytes.',
            'too_small' => 'Docker will not start a container with less than 6m of memory.',
        ],

        // A registry address Docker cannot interpret is silently IGNORED at
        // pull time — the credential simply never applies and the error is
        // identical to having none. So these are refusals at the form, and each
        // one names the specific mistake rather than saying "invalid".
        'registry' => [
            'empty' => 'Enter the registry\'s address — `docker.io` for Docker Hub, `ghcr.io`, or your own host.',
            'path' => 'That looks like a namespace or a repository, not a registry. Enter the host on its own — `ghcr.io`, not `ghcr.io/your-org`.',
            'credentials' => 'Do not put a username or password in the address. Enter the host on its own; the credentials go in the fields below.',
            'host' => 'That is not a registry address. Enter a hostname, optionally with a port — `registry.example.com` or `registry.example.com:5000`.',
            'port' => 'The port must be between 1 and 65535.',
        ],
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],

        // Laravel's default reads "The docker network new field prohibits docker
        // network from being present" — raw attribute names at a user.
        'docker_network_new' => [
            'prohibits' => 'Either pick a network from the list or name a new one — not both. They are two answers to the same question.',
        ],

        'volume_path' => [
            'required_with' => 'Give the path inside the container where this volume should appear, for example /var/lib/mysql.',
        ],

        'volume_new' => [
            'required_with' => 'Name the volume to create, or clear the path if you do not want one.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Validation Attributes
    |--------------------------------------------------------------------------
    |
    | The following language lines are used to swap our attribute placeholder
    | with something more reader friendly such as "E-Mail Address" instead
    | of "email". This simply helps us make our message more expressive.
    |
    */

    'attributes' => [
        'name' => 'name',
        'username' => 'username',
        'password' => 'password',
        'current_password' => 'current password',
        'role' => 'role',
    ],

    'start_command_shell' => 'The start command cannot contain \":token\" — it is run directly, not through a shell.',
    'start_command_wrapper' => 'Start the app with its entry file, for example \"node server.js\", not with :binary. A package manager forks the real process, so signals never reach it.',

    'port_in_use_by_app' => 'Port :port is already used by another application on this server.',

    // A network the panel created, named by a site that will join it.
    'docker_network_invalid' => 'Start the network name with a letter or number, then letters, numbers, dots, dashes or underscores.',
    'docker_network_missing' => 'There is no Docker network called \':name\' on this server. It may have been removed since this page loaded.',

    // Mounting a panel-created volume into a container site.
    'docker_volume_invalid' => 'Start the volume name with a letter or number, then letters, numbers, dots, dashes or underscores.',
    'docker_volume_missing' => 'There is no Docker volume called \':name\' on this server. It may have been removed since this page loaded.',
    'docker_mount_duplicate' => 'Two volumes cannot mount at :path. Docker would keep only one of them, without saying which.',
    'docker_mount_root' => 'Choose a path inside the container, for example /var/lib/mysql.',
    'docker_mount_site_root' => 'That path is where the site\'s own files are mounted (:path). A volume there hides them from the container — the files stay on the server but the site serves an empty volume.',
    'docker_mount_reserved' => ':path is part of the image the container boots from. An empty volume over it leaves a container that cannot start.',

    // A name the panel is about to create, so the inverse rule: not taken.
    'docker_network_taken' => 'A network called \':name\' already exists on this server. Pick it from the list above instead of creating a second one.',
    'docker_volume_taken' => 'A volume called \':name\' already exists on this server. Mount the existing one from the site\'s Container card instead of creating a second one.',
    'node_version_unsupported' => 'The :type application runs on Node :range. Choose a version in that range — outside it the application refuses to start and the site serves nothing.',
    'php_version_unsupported' => 'The :type application runs on PHP :range. Choose a version in that range — outside it the install fails part-way through, inside the application\'s own code, leaving a site to clean up.',
    'php_version_default_unsupported' => 'The :type application runs on PHP :range. Leaving this empty uses the server default (:default), which is outside that range — choose a version in the range instead.',
    'web_root_fixed' => ':type serves from :web_root and installs itself around that path, so the web root cannot be changed here. Any other value leaves the site unreachable and publishes its source.',
    'port_in_use' => 'Something on this server is already listening on port :port. Pick another, or stop what is using it.',

    'port_registered' => 'Port :port is normally used by :service. You can still use it if nothing on this server does.',

    'webhook_secret_min' => 'The webhook secret must be at least 16 characters.',

    // Per-app fail2ban thresholds
    'fail2ban.validation.maxretry_min' => 'Must ban after at least 1 failed attempt.',
    'fail2ban.validation.maxretry_max' => 'Cannot ban after more than 100 failed attempts.',
    'fail2ban.validation.findtime_min' => 'The counting window must be at least 60 seconds.',
    'fail2ban.validation.findtime_max' => 'The counting window cannot exceed 24 hours (86400 seconds).',
    'fail2ban.validation.bantime_min' => 'Ban time must be at least 0 seconds (permanent ban allowed).',
    'fail2ban.validation.bantime_max' => 'Ban time cannot exceed 7 days (604800 seconds).',

    // Custom IP/CIDR validation
    'ip_or_cidr' => 'Must be a valid IP address (e.g. 1.2.3.4) or CIDR notation (e.g. 10.0.0.0/8).',

    // Custom per-application fail2ban (raw INI)
    'jail_content_required' => 'The jail configuration is required.',
    'jail_content_string' => 'The jail configuration must be a text string.',
    'jail_content_max' => 'The jail configuration is too large (max 65535 characters).',
    'filter_content_required' => 'The filter configuration is required.',
    'filter_content_string' => 'The filter configuration must be a text string.',
    'filter_content_max' => 'The filter configuration is too large (max 65535 characters).',

    'basic_auth_conflicts' => 'Password protection cannot be used with :type. Its own interface signs in with the Authorization header, which HTTP allows only once per request — Basic Auth would consume it and make the application unreachable. :type already requires its own credentials.',
];
