<?php

namespace App\Models;

use App\Enums\ApplicationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A site the panel manages. Provisioning (files, vhost, clone) lands in later
 * phases — a record here only means "the user asked for this".
 */
#[Fillable([
    'system_user_id', 'name', 'domain', 'site_type', 'serving_profile', 'status',
    'php_version', 'node_version', 'app_port', 'rendering_type', 'web_root',
    'build_command', 'start_command',
    'git_account_id', 'repository', 'repository_url', 'branch', 'settings',
    'steps', 'failed_step', 'reference', 'last_commit', 'last_deployed_at',
    'webhook_enabled', 'webhook_provider', 'webhook_identifier', 'webhook_secret',
    'webhook_last_delivered_at',
])]
class Application extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ApplicationStatus::class,
            'settings' => 'array',
            'steps' => 'array',
            'last_deployed_at' => 'datetime',
            'webhook_enabled' => 'boolean',
            // Encrypted at rest: this is the one value that lets an
            // unauthenticated request start a deploy.
            'webhook_secret' => 'encrypted',
            'webhook_last_delivered_at' => 'datetime',
        ];
    }

    public function systemUser(): BelongsTo
    {
        return $this->belongsTo(SystemUser::class);
    }

    /**
     * Null for a public repository — those need no stored credential.
     */
    public function gitAccount(): BelongsTo
    {
        return $this->belongsTo(GitAccount::class);
    }
}
