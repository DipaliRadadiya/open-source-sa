<?php

use App\Services\Applications\SiteTypeManager;
use App\Support\FieldOptions;

/**
 * The installer dropdowns say what they mean.
 *
 * These lists used to label every option with its own code: a user picking a
 * site language chose between `he_IL` and `hi_IN` — two codes one character
 * apart naming two unrelated languages — and a PrestaShop country picker
 * offered `bq` and `bv`. The values are codes because the installers take
 * codes; that was never a reason for the *label* to be one.
 *
 * ICU already knows every one of these names in every locale the panel
 * speaks, which is what makes the honest version free rather than eighty
 * strings × eight languages of translation debt.
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

    // The two that motivated this: one character apart, entirely different
    // languages, indistinguishable when the label is the code.
    expect(optionFor($options, 'he_IL')['label'])->toBe('Hebrew (Israel)')
        ->and(optionFor($options, 'hi_IN')['label'])->toBe('Hindi (India)');
});

it('handles the hyphenated form Craft and Akaunting use', function () {
    $options = FieldOptions::localeOptions(FieldOptions::hyphenLocales());

    // Same enumeration, different punctuation — ICU normalises both, so this
    // needed no second code path.
    expect(optionFor($options, 'en-US')['label'])->toBe('English (United States)')
        ->and(optionFor($options, 'pt-BR')['label'])->toBe('Portuguese (Brazil)');
});

it('reads a bare country code as a region rather than a language', function () {
    $options = FieldOptions::countryOptions(FieldOptions::countries());

    // `Locale::getDisplayRegion('us')` is empty — a bare code is parsed as a
    // language. The '-' prefix is what makes it a region, and getting this
    // wrong would have labelled all 249 countries with their own code again,
    // silently.
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

    // The same option, read by two people, in each of their own languages —
    // the rule ActivityLogResource already follows for activity descriptions.
    expect($french)->toBe('portugais (Brésil)')
        ->and($japanese)->toBe('ポルトガル語 (ブラジル)')
        ->and($french)->not->toBe($japanese);
});

it('sorts by the label the reader sees, in their own collation', function () {
    app()->setLocale('fr');

    $labels = collect(FieldOptions::countryOptions(['de', 'us', 'at']))->pluck('label')->all();

    app()->setLocale('en');

    // Allemagne, Autriche, États-Unis — alphabetical in French, which is not
    // the order the codes are in and not the order English would give.
    expect($labels)->toBe(['Allemagne', 'Autriche', 'États-Unis']);
});

it('keeps the value exactly as the installer takes it', function () {
    // The label is for the human; the value goes on a command line. Every code
    // in the list has to survive this untouched, or the site installs in the
    // wrong language and nobody finds out until later.
    $codes = FieldOptions::wordpressLocales();
    $values = collect(FieldOptions::localeOptions($codes))->pluck('value')->all();

    expect($values)->toEqualCanonicalizing($codes)
        ->and($values)->toHaveCount(count($codes));
});

it('offers exactly what the validation rule accepts', function () {
    // The drift this whole class exists to prevent: a dropdown offering a
    // value the API then rejects reads as a broken panel.
    $type = app(SiteTypeManager::class)->find('wordpress');

    $field = collect($type->fields())->firstWhere('name', 'site_language');
    $offered = collect($field['options'])->pluck('value')->all();

    expect($offered)->toEqualCanonicalizing(FieldOptions::wordpressLocales());
});
