<?php

return [

    'accepted' => 'Поле :attribute должно быть принято.',
    'accepted_if' => 'Поле :attribute должно быть принято, когда :other равно :value.',
    'active_url' => 'Поле :attribute должно быть действительным URL.',
    'after' => 'Поле :attribute должно быть датой после :date.',
    'after_or_equal' => 'Поле :attribute должно быть датой после или равной :date.',
    'alpha' => 'Поле :attribute может содержать только буквы.',
    'alpha_dash' => 'Поле :attribute может содержать только буквы, цифры, дефисы и подчёркивания.',
    'alpha_num' => 'Поле :attribute может содержать только буквы и цифры.',
    'array' => 'Поле :attribute должно быть массивом.',
    'ascii' => 'Поле :attribute может содержать только однобайтовые буквенно-цифровые символы и знаки.',
    'before' => 'Поле :attribute должно быть датой до :date.',
    'before_or_equal' => 'Поле :attribute должно быть датой до или равной :date.',
    'between' => [
        'array' => 'Поле :attribute должно содержать от :min до :max элементов.',
        'file' => 'Размер файла в поле :attribute должен быть от :min до :max килобайт.',
        'numeric' => 'Поле :attribute должно быть между :min и :max.',
        'string' => 'Поле :attribute должно содержать от :min до :max символов.',
    ],
    'boolean' => 'Поле :attribute должно быть истинным или ложным.',
    'can' => 'Поле :attribute содержит недопустимое значение.',
    'confirmed' => 'Подтверждение поля :attribute не совпадает.',
    'current_password' => 'Неверный пароль.',
    'date' => 'Поле :attribute должно быть корректной датой.',
    'date_equals' => 'Поле :attribute должно быть датой, равной :date.',
    'date_format' => 'Поле :attribute должно соответствовать формату :format.',
    'decimal' => 'Поле :attribute должно иметь :decimal знаков после запятой.',
    'declined' => 'Поле :attribute должно быть отклонено.',
    'declined_if' => 'Поле :attribute должно быть отклонено, когда :other равно :value.',
    'different' => 'Поля :attribute и :other должны различаться.',
    'digits' => 'Поле :attribute должно содержать :digits цифр.',
    'digits_between' => 'Поле :attribute должно содержать от :min до :max цифр.',
    'dimensions' => 'Поле :attribute имеет недопустимые размеры изображения.',
    'distinct' => 'Поле :attribute содержит повторяющееся значение.',
    'doesnt_end_with' => 'Поле :attribute не должно заканчиваться одним из следующих: :values.',
    'doesnt_start_with' => 'Поле :attribute не должно начинаться с одного из следующих: :values.',
    'email' => 'Поле :attribute должно быть действительным адресом электронной почты.',
    'ends_with' => 'Поле :attribute должно заканчиваться одним из следующих: :values.',
    'enum' => 'Выбранное значение поля :attribute недопустимо.',
    'exists' => 'Выбранное значение поля :attribute недопустимо.',
    'extensions' => 'Поле :attribute должно иметь одно из следующих расширений: :values.',
    'file' => 'Поле :attribute должно быть файлом.',
    'filled' => 'Поле :attribute должно иметь значение.',
    'gt' => [
        'array' => 'Поле :attribute должно содержать более :value элементов.',
        'file' => 'Размер файла в поле :attribute должен быть больше :value килобайт.',
        'numeric' => 'Поле :attribute должно быть больше :value.',
        'string' => 'Поле :attribute должно содержать более :value символов.',
    ],
    'gte' => [
        'array' => 'Поле :attribute должно содержать :value или более элементов.',
        'file' => 'Размер файла в поле :attribute должен быть не меньше :value килобайт.',
        'numeric' => 'Поле :attribute должно быть больше или равно :value.',
        'string' => 'Поле :attribute должно содержать не менее :value символов.',
    ],
    'hex_color' => 'Поле :attribute должно быть действительным шестнадцатеричным цветом.',
    'image' => 'Поле :attribute должно быть изображением.',
    'in' => 'Выбранное значение поля :attribute недопустимо.',
    'in_array' => 'Поле :attribute должно существовать в :other.',
    'integer' => 'Поле :attribute должно быть целым числом.',
    'ip' => 'Поле :attribute должно быть действительным IP-адресом.',
    'ipv4' => 'Поле :attribute должно быть действительным IPv4-адресом.',
    'ipv6' => 'Поле :attribute должно быть действительным IPv6-адресом.',
    'json' => 'Поле :attribute должно быть корректной строкой JSON.',
    'lowercase' => 'Поле :attribute должно быть в нижнем регистре.',
    'lt' => [
        'array' => 'Поле :attribute должно содержать менее :value элементов.',
        'file' => 'Размер файла в поле :attribute должен быть меньше :value килобайт.',
        'numeric' => 'Поле :attribute должно быть меньше :value.',
        'string' => 'Поле :attribute должно содержать менее :value символов.',
    ],
    'lte' => [
        'array' => 'Поле :attribute должно содержать не более :value элементов.',
        'file' => 'Размер файла в поле :attribute должен быть не больше :value килобайт.',
        'numeric' => 'Поле :attribute должно быть меньше или равно :value.',
        'string' => 'Поле :attribute должно содержать не более :value символов.',
    ],
    'mac_address' => 'Поле :attribute должно быть действительным MAC-адресом.',
    'max' => [
        'array' => 'Поле :attribute должно содержать не более :max элементов.',
        'file' => 'Размер файла в поле :attribute должен быть не более :max килобайт.',
        'numeric' => 'Поле :attribute не должно быть больше :max.',
        'string' => 'Поле :attribute не должно содержать более :max символов.',
    ],
    'max_digits' => 'Поле :attribute должно содержать не более :max цифр.',
    'mimes' => 'Поле :attribute должно быть файлом одного из типов: :values.',
    'mimetypes' => 'Поле :attribute должно быть файлом одного из типов: :values.',
    'min' => [
        'array' => 'Поле :attribute должно содержать не менее :min элементов.',
        'file' => 'Размер файла в поле :attribute должен быть не менее :min килобайт.',
        'numeric' => 'Поле :attribute должно быть не менее :min.',
        'string' => 'Поле :attribute должно содержать не менее :min символов.',
    ],
    'min_digits' => 'Поле :attribute должно содержать не менее :min цифр.',
    'missing' => 'Поле :attribute должно отсутствовать.',
    'missing_if' => 'Поле :attribute должно отсутствовать, когда :other равно :value.',
    'missing_unless' => 'Поле :attribute должно отсутствовать, если :other не равно :value.',
    'missing_with' => 'Поле :attribute должно отсутствовать, когда :values присутствует.',
    'missing_with_all' => 'Поле :attribute должно отсутствовать, когда :values присутствуют.',
    'multiple_of' => 'Поле :attribute должно быть кратным :value.',
    'not_in' => 'Выбранное значение поля :attribute недопустимо.',
    'not_regex' => 'Формат поля :attribute недопустим.',
    'numeric' => 'Поле :attribute должно быть числом.',
    'password' => [
        'letters' => 'Поле :attribute должно содержать хотя бы одну букву.',
        'mixed' => 'Поле :attribute должно содержать хотя бы одну заглавную и одну строчную букву.',
        'numbers' => 'Поле :attribute должно содержать хотя бы одну цифру.',
        'symbols' => 'Поле :attribute должно содержать хотя бы один символ.',
        'uncompromised' => 'Указанное значение :attribute встречалось в утечке данных. Пожалуйста, выберите другое значение :attribute.',
    ],
    'present' => 'Поле :attribute должно присутствовать.',
    'present_if' => 'Поле :attribute должно присутствовать, когда :other равно :value.',
    'present_unless' => 'Поле :attribute должно присутствовать, если :other не равно :value.',
    'present_with' => 'Поле :attribute должно присутствовать, когда :values присутствует.',
    'present_with_all' => 'Поле :attribute должно присутствовать, когда :values присутствуют.',
    'prohibited' => 'Поле :attribute запрещено.',
    'prohibited_if' => 'Поле :attribute запрещено, когда :other равно :value.',
    'prohibited_unless' => 'Поле :attribute запрещено, если :other не входит в :values.',
    'prohibits' => 'Поле :attribute запрещает присутствие :other.',
    'regex' => 'Формат поля :attribute недопустим.',
    'required' => 'Поле :attribute обязательно для заполнения.',
    'required_array_keys' => 'Поле :attribute должно содержать записи для: :values.',
    'required_if' => 'Поле :attribute обязательно для заполнения, когда :other равно :value.',
    'required_if_accepted' => 'Поле :attribute обязательно для заполнения, когда :other принято.',
    'required_unless' => 'Поле :attribute обязательно для заполнения, если :other не входит в :values.',
    'required_with' => 'Поле :attribute обязательно для заполнения, когда :values присутствует.',
    'required_with_all' => 'Поле :attribute обязательно для заполнения, когда :values присутствуют.',
    'required_without' => 'Поле :attribute обязательно для заполнения, когда :values отсутствует.',
    'required_without_all' => 'Поле :attribute обязательно для заполнения, когда ни одно из :values не присутствует.',
    'same' => 'Поля :attribute и :other должны совпадать.',
    'size' => [
        'array' => 'Поле :attribute должно содержать :size элементов.',
        'file' => 'Размер файла в поле :attribute должен быть :size килобайт.',
        'numeric' => 'Поле :attribute должно быть равно :size.',
        'string' => 'Поле :attribute должно содержать :size символов.',
    ],
    'starts_with' => 'Поле :attribute должно начинаться с одного из следующих: :values.',
    'string' => 'Поле :attribute должно быть строкой.',
    'timezone' => 'Поле :attribute должно быть действительным часовым поясом.',
    'unique' => 'Такое значение поля :attribute уже занято.',
    'uploaded' => 'Не удалось загрузить поле :attribute.',
    'uppercase' => 'Поле :attribute должно быть в верхнем регистре.',
    'url' => 'Поле :attribute должно быть действительным URL.',
    'ulid' => 'Поле :attribute должно быть действительным ULID.',
    'uuid' => 'Поле :attribute должно быть действительным UUID.',

    'any_of' => 'Поле :attribute недопустимо.',
    'base64' => 'Поле :attribute должно быть корректной строкой Base64.',
    'contains' => 'В поле :attribute отсутствует обязательное значение.',
    'doesnt_contain' => 'Поле :attribute не должно содержать ни одно из следующих значений: :values.',
    'encoding' => 'Поле :attribute должно быть в кодировке :encoding.',
    'in_array_keys' => 'Поле :attribute должно содержать хотя бы один из следующих ключей: :values.',
    'list' => 'Поле :attribute должно быть списком.',
    'prohibited_if_accepted' => 'Поле :attribute запрещено, когда :other принято.',
    'prohibited_if_declined' => 'Поле :attribute запрещено, когда :other отклонено.',
    'required_if_declined' => 'Поле :attribute обязательно для заполнения, когда :other отклонено.',

    'custom' => [

        // Docker accepts a CPU quota larger than the machine and silently
        // clamps it, so the refusal has to come from here — and it names what
        // the server actually has rather than saying "invalid".
        'cpu_limit' => [
            'format' => 'Укажите количество CPU, максимум с двумя десятичными знаками — 1, 1.5, 0.5.',
            'positive' => 'Минимальный лимит CPU, который принимает Docker, — :minimum. Оставьте поле пустым, чтобы не задавать лимит.',
            'too_many' => 'На этом сервере :cores CPU, и Docker не запустит контейнер, который просит больше. Выберите :cores или меньше.',
        ],

        'memory_limit' => [
            'format' => 'Укажите размер с единицей — 512m или 2g. Число без единицы Docker понимает как байты, а не мегабайты.',
            'too_small' => 'Docker не запустит контейнер с памятью менее 6m.',
        ],

        // A registry address Docker cannot interpret is silently IGNORED at
        // pull time — the credential simply never applies and the error is
        // identical to having none. So these are refusals at the form, and each
        // one names the specific mistake rather than saying "invalid".
        'registry' => [
            'empty' => 'Укажите адрес реестра — `docker.io` для Docker Hub, `ghcr.io` или ваш собственный хост.',
            'path' => 'Это похоже на пространство имён или репозиторий, а не на реестр. Укажите только хост — `ghcr.io`, а не `ghcr.io/ваша-организация`.',
            'credentials' => 'Не указывайте имя пользователя или пароль в адресе. Укажите только хост; учётные данные вводятся в полях ниже.',
            'host' => 'Это не адрес реестра. Укажите имя хоста, при необходимости с портом — `registry.example.com` или `registry.example.com:5000`.',
            'port' => 'Порт должен быть от 1 до 65535.',
        ],
        'attribute-name' => [
            'rule-name' => 'custom-message',
        ],

        // Laravel's default reads "The docker network new field prohibits docker
        // network from being present" — raw attribute names at a user.
        'docker_network_new' => [
            'prohibits' => 'Выберите сеть из списка или укажите имя новой — не одновременно. Это два ответа на один вопрос.',
        ],

        'volume_path' => [
            'required_with' => 'Укажите путь внутри контейнера, где должен появиться этот том, например /var/lib/mysql.',
        ],

        'volume_new' => [
            'required_with' => 'Укажите имя тома для создания или очистите путь, если том не нужен.',
        ],
    ],

    'attributes' => [
        'name' => 'имя',
        'username' => 'имя пользователя',
        'password' => 'пароль',
        'current_password' => 'текущий пароль',
        'role' => 'роль',
    ],

    'start_command_shell' => 'Команда запуска не может содержать «:token» — она выполняется напрямую, без оболочки.',
    'process_instances_entrypoint' => 'Чтобы запустить более одного процесса, команда запуска должна указывать на скрипт — например «node server.js». PM2 создаёт кластер, форкая этот файл; с чем-либо другим он молча запускает всего один процесс.',
    'start_command_wrapper' => 'Запускайте приложение через входной файл, например «node server.js», а не через :binary. Менеджер пакетов порождает реальный процесс отдельно, поэтому сигналы до него не доходят.',

    'port_in_use_by_app' => 'Порт :port уже занят другим приложением на этом сервере.',

    // A network the panel created, named by a site that will join it.
    'docker_network_invalid' => 'Начните имя сети с буквы или цифры, далее используйте буквы, цифры, точки, дефисы или подчёркивания.',
    'docker_network_missing' => 'На этом сервере нет сети Docker с именем \':name\'. Возможно, она была удалена после загрузки страницы.',

    // Mounting a panel-created volume into a container site.
    'docker_volume_invalid' => 'Начните имя тома с буквы или цифры, далее используйте буквы, цифры, точки, дефисы или подчёркивания.',
    'docker_volume_missing' => 'На этом сервере нет тома Docker с именем \':name\'. Возможно, он был удалён после загрузки страницы.',
    'docker_mount_duplicate' => 'Два тома нельзя смонтировать в :path. Docker оставит только один и не скажет, какой именно.',
    'docker_mount_root' => 'Укажите путь внутри контейнера, например /var/lib/mysql.',
    'docker_mount_site_root' => 'По этому пути смонтированы собственные файлы сайта (:path). Том здесь скрывает их от контейнера — файлы остаются на сервере, но сайт отдаёт пустой том.',
    'docker_mount_reserved' => ':path входит в образ, с которого запускается контейнер. Пустой том поверх него оставит контейнер, который не сможет запуститься.',
    'docker_mount_characters' => 'Используйте только буквы, цифры, точки, дефисы, подчёркивания и косые черты, начиная с /, например /var/lib/mysql.',

    // A name the panel is about to create, so the inverse rule: not taken.
    'docker_network_taken' => 'Сеть с именем \':name\' уже есть на этом сервере. Выберите её в списке выше, а не создавайте вторую.',
    'docker_volume_taken' => 'Том с именем \':name\' уже есть на этом сервере. Подключите существующий на карточке «Контейнер» сайта, а не создавайте второй.',
    'node_version_unsupported' => 'Приложение :type работает на Node :range. Выберите версию из этого диапазона — вне его приложение откажется запускаться и сайт ничего не отдаст.',
    'php_version_unsupported' => 'Приложение :type работает на PHP :range. Выберите версию из этого диапазона — вне его установка обрывается на полпути, внутри кода самого приложения, и оставляет сайт, который придётся убирать.',
    'php_version_default_unsupported' => 'Приложение :type работает на PHP :range. Если оставить это поле пустым, будет использована серверная версия по умолчанию (:default) — она вне диапазона. Выберите версию из диапазона.',
    'web_root_fixed' => ':type обслуживается из :web_root и устанавливается вокруг этого пути, поэтому корень сайта здесь изменить нельзя. Любое другое значение сделает сайт недоступным и опубликует его исходный код.',
    'web_root_missing' => 'Папка :path не существует, поэтому сайту нечего будет отдавать. Сначала создайте её или загрузите туда файлы, а затем укажите её как корень сайта.',
    'web_root_symlink' => 'Часть этого пути, :path, — символическая ссылка, поэтому сайт мог бы отдавать файлы из другого места. Укажите настоящую папку внутри сайта.',
    'port_in_use' => 'На этом сервере что-то уже слушает порт :port. Выберите другой или остановите то, что его занимает.',

    'port_registered' => 'Порт :port обычно используется :service. Вы всё равно можете его использовать, если на этом сервере он свободен.',
    'application_name_immutable' => 'Имя сайта задаётся при создании и не меняется — по нему называются его файлы конфигурации на сервере. Если нужно другое имя, создайте новый сайт.',
    'site_name_taken_pool' => 'На этом сервере уже есть файл пула PHP «:name.conf», созданный не панелью. Выберите другое имя.',
    'site_name_taken_vhost' => 'На этом сервере уже есть конфигурация веб-сервера «:name», созданная не панелью — в том числе собственная конфигурация панели. Выберите другое имя.',

    'webhook_secret_min' => 'Секрет вебхука должен содержать не менее 16 символов.',

    // Per-app fail2ban thresholds
    'fail2ban.validation.maxretry_min' => 'Блокировать нужно минимум после 1 неудачной попытки.',
    'fail2ban.validation.maxretry_max' => 'Нельзя блокировать более чем после 100 неудачных попыток.',
    'fail2ban.validation.findtime_min' => 'Окно подсчёта должно быть не менее 60 секунд.',
    'fail2ban.validation.findtime_max' => 'Окно подсчёта не может превышать 24 часа (86400 секунд).',
    'fail2ban.validation.bantime_min' => 'Время блокировки должно быть не менее 0 секунд (постоянная блокировка разрешена).',
    'fail2ban.validation.bantime_max' => 'Время блокировки не может превышать 7 дней (604800 секунд).',
    'ip_or_cidr' => 'Должен быть корректный IP-адрес (например, 1.2.3.4) или запись CIDR (например, 10.0.0.0/8).',

    'jail_content_required' => 'Конфигурация jail обязательна.',
    'jail_content_string' => 'Конфигурация jail должна быть текстом.',
    'jail_content_max' => 'Конфигурация jail слишком большая (макс. 65535 символов).',
    'filter_content_required' => 'Конфигурация фильтра обязательна.',
    'filter_content_string' => 'Конфигурация фильтра должна быть текстом.',
    'filter_content_max' => 'Конфигурация фильтра слишком большая (макс. 65535 символов).',
    'basic_auth_conflicts' => 'Защиту паролем нельзя использовать с :type. Его собственный интерфейс входит через заголовок Authorization, который HTTP допускает только один раз на запрос — Basic-аутентификация займёт его и сделает приложение недоступным. :type уже требует собственные учётные данные.',
    'basic_auth_username' => 'Имя пользователя может содержать только латинские буквы, цифры и символы, без пробелов и двоеточия (:). Браузеры не могут надёжно передавать другие символы.',
    'git_repository_unreachable' => 'Не удалось получить доступ к репозиторию. Проверьте адрес и то, что аккаунт (или репозиторий, если он публичный) разрешает доступ.',
    'git_host_unreachable' => 'Сервер не смог подключиться к git-хостингу. Проверьте сетевое подключение и повторите попытку.',
    'git_branch_missing' => 'Ветки ":branch" нет в этом репозитории.',
];
