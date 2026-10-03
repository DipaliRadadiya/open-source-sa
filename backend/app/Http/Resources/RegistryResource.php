<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One registry credential, minus the credential.
 *
 * The token is **absent**, not masked and not truncated — a mask is a length
 * disclosure and a truncation is a prefix disclosure, and a Docker PAT's prefix
 * identifies the account it belongs to. There is also no reveal endpoint, unlike
 * the container-secrets panel: those secrets are ones the panel generated and the
 * user needs in order to log into their own app, whereas this one the user
 * already has. Nothing is served by handing it back.
 *
 * `has_credentials` reports only whether a token is stored, so the UI can show a
 * "credential set" state and a rotation prompt without the value going anywhere
 * near a response.
 */
class RegistryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,

            // As the user typed it, so the form round-trips what they entered.
            'registry' => $this->registry,
            // And as Docker will see it, because these differ for Docker Hub and
            // the difference is the single most confusing thing about this
            // feature — a user who typed `docker.io` should be able to see that
            // the panel is writing the v1 index URL, rather than wondering why.
            'auth_key' => $this->authKey(),
            'is_docker_hub' => $this->isDockerHub(),

            'username' => $this->username,
            'has_credentials' => $this->hasCredentials(),

            // How many sites pull with this. The number the delete confirmation
            // needs, and only loaded when the caller asked for it.
            'applications_count' => $this->whenCounted('applications'),

            // The last probe. `never_tested` is a distinct state from `failed`:
            // "we have not asked" is not the user's problem to fix.
            'last_tested_at' => $this->last_tested_at?->format('d-m-Y H:i:s'),
            // The age as well as the verdict — a registry tested forty days ago
            // is not a registry known to work today.
            'last_tested_at_human' => $this->last_tested_at?->diffForHumans(),
            'last_test_success' => $this->last_test_success,
            // A stable category key, never Docker's own text, which quotes the
            // registry URL and the username back.
            'last_test_error' => $this->last_test_error,
            'status' => $this->testStatus(),
            'status_title' => __('docker.registry_status.'.$this->testStatus()),

            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'updated_at' => $this->updated_at?->format('d-m-Y H:i:s'),
        ];
    }
}
