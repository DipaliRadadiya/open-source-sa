<?php

use App\Services\Applications\SiteTypeManager;
use App\Support\FieldOptions;

/**
 * The installer dropdowns say what they mean.
 *
 * Values are codes because the installers take codes, but the labels must be human names
 * (not `he_IL` / `hi_IN`, or `bq` / `bv`). ICU supplies the names in every panel locale, so no
 * translation strings are needed.
 */
beforeEach(function () {
    FieldOptions::flushLabelCache();
});

afterEach(function () {
    FieldOptions::flushLabelCache();
});

/** The option for a given code, from a field's option list. */
function optionFor(array $options, string $value): ?array
{
    return collect($options)->firstWhere('value', $value);
}

it('labels a locale with its name, not its code', function () {
    $options = FieldOptions::localeOptions(FieldOptions::wordpressLocales());

    expect(optionFor($options, 'en_US'))->toBe([
        'value' => 'en_US',
        'label' => 'English (United States)',
    ]);

    // One character apart, entirely different languages: indistinguishable if the label is the code.
    expect(optionFor($options, 'he_IL')['label'])->toBe('Hebrew (Israel)')
        ->and(optionFor($options, 'hi_IN')['label'])->toBe('Hindi (India)');
});

it('handles the hyphenated form Craft and Akaunting use', function () {
    $options = FieldOptions::localeOptions(FieldOptions::hyphenLocales());

    // Same enumeration, different punctuation; ICU normalises both.
    expect(optionFor($options, 'en-US')['label'])->toBe('English (United States)')
        ->and(optionFor($options, 'pt-BR')['label'])->toBe('Portuguese (Brazil)');
});

it('reads a bare country code as a region rather than a language', function () {
    $options = FieldOptions::countryOptions(FieldOptions::countries());

    // `Locale::getDisplayRegion('us')` is empty: a bare code is parsed as a language. The '-'
    // prefix makes it a region; without it every country is labelled with its own code again.
    expect(optionFor($options, 'us')['label'])->toBe('United States')
        ->and(optionFor($options, 'de')['label'])->toBe('Germany')
        ->and(optionFor($options, 'bq')['label'])->not->toBe('bq');
});

it('labels a bare language code', function () {
    $options = FieldOptions::languageOptions(FieldOptions::languages());

    expect(optionFor($options, 'sw')['label'])->toBe('Swahili')
        ->and(optionFor($options, 'cy')['label'])->toBe('Welsh');
});

it('answers in the viewer\'s language, not the site\'s', function () {
    app()->setLocale('fr');
    $french = optionFor(FieldOptions::localeOptions(['pt_BR']), 'pt_BR')['label'];

    app()->setLocale('ja');
    $japanese = optionFor(FieldOptions::localeOptions(['pt_BR']), 'pt_BR')['label'];

    app()->setLocale('en');

    // The same option, read in each person's own language (as ActivityLogResource does).
    expect($french)->toBe('portugais (Brésil)')
        ->and($japanese)->toBe('ポルトガル語 (ブラジル)')
        ->and($french)->not->toBe($japanese);
});

it('sorts by the label the reader sees, in their own collation', function () {
    app()->setLocale('fr');

    $labels = collect(FieldOptions::countryOptions(['de', 'us', 'at']))->pluck('label')->all();

    app()->setLocale('en');

    // Allemagne, Autriche, États-Unis: sorted in French, not by code or English name.
    expect($labels)->toBe(['Allemagne', 'Autriche', 'États-Unis']);
});

it('keeps the value exactly as the installer takes it', function () {
    // The label is for the human; the value goes on a command line. Every code must survive
    // untouched, or the site installs in the wrong language.
    $codes = FieldOptions::wordpressLocales();
    $values = collect(FieldOptions::localeOptions($codes))->pluck('value')->all();

    expect($values)->toEqualCanonicalizing($codes)
        ->and($values)->toHaveCount(count($codes));
});

it('offers exactly what the validation rule accepts', function () {
    // Guards against drift: a dropdown offering a value the API rejects reads as a broken panel.
    $type = app(SiteTypeManager::class)->find('wordpress');

    $field = collect($type->fields())->firstWhere('name', 'site_language');
    $offered = collect($field['options'])->pluck('value')->all();

    expect($offered)->toEqualCanonicalizing(FieldOptions::wordpressLocales());
});
