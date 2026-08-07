<?php

use Illuminate\Support\Str;
use Pdo\Mysql;

return [

    /*
    |--------------------------------------------------------------------------
    | Default Database Connection Name
    |--------------------------------------------------------------------------
    |
    | Here you may specify which of the database connections below you wish
    | to use as your default connection for database operations. This is
    | the connection which will be utilized unless another connection
    | is explicitly specified when you execute a query / statement.
    |
    */

    'default' => env('DB_CONNECTION', 'sqlite'),

    /*
    |--------------------------------------------------------------------------
    | Database Connections
    |--------------------------------------------------------------------------
    |
    | Below are all of the database connections defined for your application.
    | An example configuration is provided for each database system which
    | is supported by Laravel. You're free to add / remove connections.
    |
    */

    'connections' => [

        'sqlite' => [
            'driver' => 'sqlite',
            'url' => env('DB_URL'),
            'database' => env('DB_DATABASE', database_path('database.sqlite')),
            'prefix' => '',
            'foreign_key_constraints' => env('DB_FOREIGN_KEYS', true),
            /*
             * SQLite defaults to the rollback journal, where a writer takes
             * an exclusive lock on the whole file and readers block writers.
             * Every API request writes — the rate limiter alone bumps a
             * counter in the cache table — so two concurrent requests are
             * enough to produce "database is locked", and the frontend fires
             * several on every page load.
             *
             * The busy timeout does not rescue that on its own: SQLite
             * returns BUSY immediately, without invoking the busy handler,
             * when waiting would deadlock — which is exactly the shape of a
             * request that reads and then writes while another holds a
             * shared lock. WAL removes the deadlock by letting readers and
             * one writer proceed together, which is also what makes the
             * timeout meaningful for the contention that remains.
             *
             * NORMAL synchronous is the documented safe pairing with WAL: it
             * can lose the last transactions to a power cut, but never
             * corrupts the database.
             */
            // 15s rather than 5s. A cushion, not a fix: the real cost is a
            // writer holding the single write lock for seconds at a time, and
            // 5s was short enough that an ordinary `ufw` reload could outlast
            // it and fail an unrelated request with "database is locked".
            'busy_timeout' => env('DB_BUSY_TIMEOUT', 15000),
            'journal_mode' => env('DB_JOURNAL_MODE', 'WAL'),
            'synchronous' => env('DB_SYNCHRONOUS', 'NORMAL'),

            /*
             * IMMEDIATE, not the DEFERRED that both SQLite and Laravel default
             * to. This is what finally makes `busy_timeout` above do its job.
             *
             * A deferred transaction takes a *read* lock on its first read and
             * only tries to upgrade to a write lock on its first write. If
             * another connection wrote in between, SQLite cannot wait for that
             * upgrade — two readers both waiting to upgrade would deadlock —
             * so it returns BUSY *immediately*, without ever calling the busy
             * handler. That is the case the comment above describes, and it is
             * why a 15-second timeout still produced instant "database is
             * locked" errors: the timeout was never consulted.
             *
             * IMMEDIATE takes the write lock up front. There is no upgrade, so
             * there is no deadlock to avoid, so SQLite is free to wait — and
             * the busy timeout applies. A contended write now queues for up to
             * 15s instead of failing on the spot.
             *
             * The cost is real but small: a write transaction blocks other
             * writers from its first statement rather than its first write.
             * On a panel whose transactions are a handful of row writes, that
             * is a few milliseconds of extra exclusivity in exchange for the
             * error class disappearing.
             *
             * Requires PHP 8.4+ — SQLiteConnection only honours this setting
             * there, and falls back to PDO's own BEGIN otherwise. This project
             * runs 8.4.
             */
            'transaction_mode' => 'IMMEDIATE',

            /*
             * Note for anyone tuning further: Laravel's SQLite connector only
             * applies three pragmas — busy_timeout, journal_mode and
             * synchronous. Adding `journal_size_limit`, `wal_autocheckpoint`,
             * `cache_size` or `temp_store` here does nothing at all; they are
             * read by no one. Setting those needs a connection hook, not a
             * config key, so nothing should be added here expecting it to work.
             */
        ],

        'mysql' => [
            'driver' => 'mysql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'mariadb' => [
            'driver' => 'mariadb',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '3306'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'unix_socket' => env('DB_SOCKET', ''),
            'charset' => env('DB_CHARSET', 'utf8mb4'),
            'collation' => env('DB_COLLATION', 'utf8mb4_unicode_ci'),
            'prefix' => '',
            'prefix_indexes' => true,
            'strict' => true,
            'engine' => null,
            'options' => extension_loaded('pdo_mysql') ? array_filter([
                Mysql::ATTR_SSL_CA => env('MYSQL_ATTR_SSL_CA'),
            ]) : [],
        ],

        'pgsql' => [
            'driver' => 'pgsql',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', '127.0.0.1'),
            'port' => env('DB_PORT', '5432'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => env('DB_SSLMODE', 'prefer'),
        ],

        'sqlsrv' => [
            'driver' => 'sqlsrv',
            'url' => env('DB_URL'),
            'host' => env('DB_HOST', 'localhost'),
            'port' => env('DB_PORT', '1433'),
            'database' => env('DB_DATABASE', 'laravel'),
            'username' => env('DB_USERNAME', 'root'),
            'password' => env('DB_PASSWORD', ''),
            'charset' => env('DB_CHARSET', 'utf8'),
            'prefix' => '',
            'prefix_indexes' => true,
            // 'encrypt' => env('DB_ENCRYPT', 'yes'),
            // 'trust_server_certificate' => env('DB_TRUST_SERVER_CERTIFICATE', 'false'),
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Migration Repository Table
    |--------------------------------------------------------------------------
    |
    | This table keeps track of all the migrations that have already run for
    | your application. Using this information, we can determine which of
    | the migrations on disk haven't actually been run on the database.
    |
    */

    'migrations' => [
        'table' => 'migrations',
        'update_date_on_publish' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | Redis Databases
    |--------------------------------------------------------------------------
    |
    | Redis is an open source, fast, and advanced key-value store that also
    | provides a richer body of commands than a typical key-value system
    | such as Memcached. You may define your connection settings here.
    |
    */

    'redis' => [

        'client' => env('REDIS_CLIENT', 'phpredis'),

        'options' => [
            'cluster' => env('REDIS_CLUSTER', 'redis'),
            'prefix' => env('REDIS_PREFIX', Str::slug((string) env('APP_NAME', 'laravel')).'-database-'),
            'persistent' => env('REDIS_PERSISTENT', false),
        ],

        'default' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_DB', '0'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

        'cache' => [
            'url' => env('REDIS_URL'),
            'host' => env('REDIS_HOST', '127.0.0.1'),
            'username' => env('REDIS_USERNAME'),
            'password' => env('REDIS_PASSWORD'),
            'port' => env('REDIS_PORT', '6379'),
            'database' => env('REDIS_CACHE_DB', '1'),
            'max_retries' => env('REDIS_MAX_RETRIES', 3),
            'backoff_algorithm' => env('REDIS_BACKOFF_ALGORITHM', 'decorrelated_jitter'),
            'backoff_base' => env('REDIS_BACKOFF_BASE', 100),
            'backoff_cap' => env('REDIS_BACKOFF_CAP', 1000),
        ],

    ],

];
