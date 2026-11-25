<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One registry credential, minus the credential.
 *
 * The token is **absent**, not masked or truncated: a mask discloses the length and a prefix
 * identifies the account. There is no reveal endpoint either; the user already has the token.
 *
 * `has_credentials` only says whether a token is stored, so the UI can show a "credential set"
 * state without the value going near a response.
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
            // And as Docker will see it: these differ for Docker Hub (`docker.io` is written as
            // the v1 index URL), and the user should be able to see that.
            'auth_key' => $this->authKey(),
            'is_docker_hub' => $this->isDockerHub(),

            'username' => $this->username,
            'has_credentials' => $this->hasCredentials(),

            // How many sites pull with this (for the delete confirmation); only when requested.
            'applications_count' => $this->whenCounted('applications'),

            // The last probe. `never_tested` is a distinct state from `failed`.
            'last_tested_at' => $this->last_tested_at?->format('d-m-Y H:i:s'),
            // The age as well as the verdict: an old test says little about today.
            'last_tested_at_human' => $this->last_tested_at?->diffForHumans(),
            'last_test_success' => $this->last_test_success,
            // A stable category key, never Docker's own text (it quotes the URL and username).
            'last_test_error' => $this->last_test_error,
            'status' => $this->testStatus(),
            'status_title' => __('docker.registry_status.'.$this->testStatus()),

            'created_at' => $this->created_at?->format('d-m-Y H:i:s'),
            'updated_at' => $this->updated_at?->format('d-m-Y H:i:s'),
        ];
    }
}
