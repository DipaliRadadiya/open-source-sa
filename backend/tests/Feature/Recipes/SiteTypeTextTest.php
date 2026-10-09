<?php

use App\Services\Applications\SiteTypeText;

require_once __DIR__.'/../../Support/Recipes/RecipeTestSupport.php';

beforeEach(fn () => rc03RecipeSetup());

it('uses recipe text in the active locale and falls back to English without adding translator lines', function () {
    app()->setLocale('ja');
    $text = app(SiteTypeText::class);
    expect($text->title('demo_single'))->toBe('Demo')->and($text->tagline('demo_single'))->toBe('デモアプリ');
    app()->setLocale('fr');
    expect($text->tagline('demo_single'))->toBe('Demo application')
        ->and($text->title('wordpress'))->toBe(__('application.types.wordpress.title'))
        ->and($text->tagline('wordpress'))->toBe(__('application.types.wordpress.tagline'))
        ->and(__('application.fields.memory_limit'))->not->toBe('application.fields.memory_limit');
});
