<?php

namespace App\Http\Resources;

use App\Enums\LoginShell;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SystemUserResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
            'home_path' => $this->home_path,
            'shell' => $this->shell,
            // The path is what the server needs; these two are what a person
            // needs. `shell_allows_login` is null for a shell we do not offer,
            // which an adopted server's users can legitimately have — null
            // means "unknown", not "cannot log in".
            'shell_title' => LoginShell::titleFor($this->shell),
            'shell_allows_login' => LoginShell::allowsLoginFor($this->shell),
            'sudo' => (bool) $this->sudo,
            'ssh_access' => (bool) $this->ssh_access,
            // Plaintext, per operator decision — shown so an admin can copy it
            // for server login. Null until a password has been set.
            //
            // And null for anyone who may only view system users (SU-01): it
            // is a working SSH/SFTP login for this server, and it was handed
            // to every read-only role through the list. `password_known`
            // still says whether one is set.
            'password' => $request->user()?->canManage('system_user') ? $this->password : null,
            'password_known' => $this->password !== null,
            // Index eager-loads only id+name; show eager-loads full detail.
            'applications' => $this->whenLoaded('applications', fn () => $this->applications->map(fn ($app) => array_filter([
                'id' => $app->id,
                'name' => $app->name,
                'domain' => $app->domain,
                'site_type' => $app->site_type,
                'php_version' => $app->php_version,
                'status' => $app->status,
            ], fn ($v) => $v !== null))->all()),
            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
