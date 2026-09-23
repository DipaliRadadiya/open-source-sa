<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis",
    |          "deferred", "background", "failover", "null"
    |
    | `retry_after` is the reservation window: how long the queue waits before
    | deciding a reserved job is dead and letting another worker take it. It
    | MUST outlast the longest job, or a job that is merely slow is picked up a
    | second time while the first is still running. This panel's jobs change
    | the server — a second worker re-entering a half-applied provision is the
    | worst failure mode it has, and `$tries = 1` does not prevent it because
    | the second run is a new reservation, not a retry.
    |
    | Laravel's 90s default was left in place while installers grew to 1800s
    | (Nextcloud's download, NodeBB's `npm install`). 2400 cleared
    | `ProvisioningBudget::longest()` — but only that. `RunBackup` and
    | `RunRestore` declare 3600, so a backup of a large site outlived its own
    | reservation by twenty minutes: the queue released it as dead and ran it
    | again once the worker was free. A backup running twice is waste; a
    | restore running twice re-extracts an archive over a site somebody may
    | have started using again.
    |
    | 4200 clears the longest job rather than the longest provision, and the
    | test now checks every job's timeout instead of one of them.
    |
    | Then `BACKUP_JOB_TIMEOUT` became 21600 so a 100 GB site could finish, and
    | only the redis window was derived from it — leaving `database` (which is
    | this file's *default* connection) and `beanstalkd` at 4200, against a job
    | allowed six hours. A 103 GB backup takes about 88 minutes, so that is not
    | a theoretical gap: on any panel not running redis, a large backup was
    | dispatched a second time while the first was still uploading, both
    | writing the same key. All three windows are derived from the one number
    | now, because that is the property that keeps being violated by editing
    | one of them.
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env(
                'DB_QUEUE_RETRY_AFTER',
                (int) env('BACKUP_JOB_TIMEOUT', 21600) + 600,
            ),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env(
                'BEANSTALKD_QUEUE_RETRY_AFTER',
                (int) env('BACKUP_JOB_TIMEOUT', 21600) + 600,
            ),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            /*
             * Must exceed the longest job's timeout, or Redis hands that job to
             * a second worker while the first is still working on it — two
             * concurrent backups of one site, writing the same archive key.
             *
             * Derived from the backup timeout rather than set beside it: these
             * were two independent literals (3600 and 4200) and raising either
             * one alone reintroduces the bug silently, because nothing fails —
             * the duplicate run just happens. The grace matches
             * `ExpiresUniqueLock::UNIQUE_LOCK_GRACE` for the same reason the
             * reaper borrows its bound: three numbers that must agree should
             * have one source.
             */
            'retry_after' => (int) env(
                'REDIS_QUEUE_RETRY_AFTER',
                (int) env('BACKUP_JOB_TIMEOUT', 21600) + 600,
            ),
            'block_for' => null,
            'after_commit' => false,
        ],

        'deferred' => [
            'driver' => 'deferred',
        ],

        'background' => [
            'driver' => 'background',
        ],

        'failover' => [
            'driver' => 'failover',
            'connections' => [
                'database',
                'deferred',
            ],
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
