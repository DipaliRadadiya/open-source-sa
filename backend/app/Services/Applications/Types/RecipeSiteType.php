<?php

namespace App\Services\Applications\Types;

use App\Models\Application;
use App\Services\Recipes\Recipe;
use App\Services\Recipes\RecipeClaims;
use LogicException;

final class RecipeSiteType extends AbstractDockerAppType
{
    public function __construct(private readonly Recipe $recipe) {}

    public function recipe(): Recipe
    {
        return $this->recipe;
    }

    public function name(): string
    {
        return $this->recipe->slug;
    }

    public function category(): string
    {
        return $this->recipe->category;
    }

    public function icon(): string
    {
        return $this->recipe->icon;
    }

    public function popular(): bool
    {
        return $this->recipe->popular;
    }

    public function composeTemplate(): string
    {
        throw new LogicException('Recipe types render through RecipeRenderer.');
    }

    public function containerPort(): int
    {
        return $this->recipe->containerPort;
    }

    public function volumeRoles(): array
    {
        return $this->recipe->volumes;
    }

    public function generatedSecrets(): array
    {
        return $this->recipe->generatedSecretKeys();
    }

    public function complexSecrets(): array
    {
        return $this->recipe->complexSecretKeys();
    }

    public function panelOnlySecrets(): array
    {
        return $this->recipe->panelOnlySecretKeys();
    }

    public function urlEnvKey(): ?string
    {
        return $this->recipe->urlEnvKey;
    }

    public function defaultMemoryLimit(): ?string
    {
        return $this->recipe->memoryFloor;
    }

    public function starterFiles(): array
    {
        return $this->recipe->starterFiles;
    }

    public function firstRunClaim(Application $application): ?array
    {
        return app(RecipeClaims::class)->for($this->recipe, $application);
    }

    public function fields(): array
    {
        $fields = parent::fields();

        foreach ($this->recipe->inputs as $input) {
            $email = $input['name'] === 'admin_email';
            $fields[] = $this->field(
                $input['name'],
                $email ? 'email' : 'text',
                required: $input['required'],
                extra: $email ? ['placeholder' => __('application.placeholders.admin_email')] : [],
            );
        }

        return $fields;
    }

    public function rules(): array
    {
        $rules = parent::rules();

        foreach ($this->recipe->inputs as $input) {
            $rules[$input['name']] = [
                $input['required'] ? 'required' : 'nullable',
                ...($input['name'] === 'admin_email'
                    ? ['email', 'max:255']
                    : ['string', 'max:32', 'regex:/^[a-z][a-z0-9_-]{2,31}$/']),
            ];
        }

        return $rules;
    }
}
