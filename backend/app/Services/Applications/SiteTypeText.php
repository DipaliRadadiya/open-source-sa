<?php

namespace App\Services\Applications;

use App\Services\Recipes\RecipeRegistry;

final class SiteTypeText
{
    public function __construct(private RecipeRegistry $recipes) {}

    public function title(string $name): string
    {
        return $this->recipes->find($name)->title ?? __("application.types.{$name}.title");
    }

    public function tagline(string $name): string
    {
        return $this->recipes->find($name)?->tagline(app()->getLocale()) ?? __("application.types.{$name}.tagline");
    }
}
