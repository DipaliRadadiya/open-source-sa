<?php

namespace App\Support;

/**
 * Static enumerations for site-type installer fields.
 *
 * These were free-text inputs. The values are not free text — they are codes a
 * specific installer accepts, and a typo produces a site installed in the
 * wrong language or a country that does not exist, discovered later by the
 * person who owns the site.
 *
 * One source per list, shared by the field definition and the validation rule.
 * The two drifting apart is the failure this class exists to prevent: a
 * dropdown offering a value the API rejects wastes the user's time and looks
 * like a bug in the panel rather than in its data.
 *
 * The lists are complete for their domain rather than a popular subset,
 * because a subset is a decision to exclude somebody. Where an installer
 * genuinely supports fewer values than the standard defines, the list follows
 * the installer.
 */
class FieldOptions
{
    /**
     * ISO 3166-1 alpha-2, lowercase — the form PrestaShop's installer takes.
     *
     * @return list<string>
     */
    public static function countries(): array
    {
        return explode(' ', 'ad ae af ag ai al am ao aq ar as at au aw ax az ba bb bd be bf bg bh bi bj bl bm bn bo bq br bs bt bv bw by bz ca cc cd cf cg ch ci ck cl cm cn co cr cu cv cw cx cy cz de dj dk dm do dz ec ee eg eh er es et fi fj fk fm fo fr ga gb gd ge gf gg gh gi gl gm gn gp gq gr gs gt gu gw gy hk hm hn hr ht hu id ie il im in io iq ir is it je jm jo jp ke kg kh ki km kn kp kr kw ky kz la lb lc li lk lr ls lt lu lv ly ma mc md me mf mg mh mk ml mm mn mo mp mq mr ms mt mu mv mw mx my mz na nc ne nf ng ni nl no np nr nu nz om pa pe pf pg ph pk pl pm pn pr ps pt pw py qa re ro rs ru rw sa sb sc sd se sg sh si sj sk sl sm sn so sr ss st sv sx sy sz tc td tf tg th tj tk tl tm tn to tr tt tv tw tz ua ug um us uy uz va vc ve vg vi vn vu wf ws ye yt za zm zw');
    }

    /**
     * ISO 639-1, the two-letter form PrestaShop's installer takes.
     *
     * @return list<string>
     */
    public static function languages(): array
    {
        return explode(' ', 'af ar az be bg bn bs ca cs cy da de el en eo es et eu fa fi fo fr ga gl he hi hr hu hy id is it ja ka kk km ko ku lt lv mk ml mn ms mt nb nl nn no pl pt ro ru si sk sl sq sr sv sw ta te th tl tr uk ur uz vi zh');
    }

    /**
     * WordPress locale codes, as `wp core install --locale` takes them.
     *
     * Note the shape: underscore, and a region on most but not all. `ja` and
     * `he_IL` are both correct — WordPress is not consistent here and the list
     * has to follow it rather than a rule.
     *
     * @return list<string>
     */
    public static function wordpressLocales(): array
    {
        return explode(' ', 'en_US en_GB en_AU en_CA en_NZ en_ZA af ar az bg_BG bn_BD bs_BA ca cs_CZ cy da_DK de_DE de_AT de_CH el eo es_ES es_AR es_CL es_CO es_MX es_PE es_VE et eu fa_IR fi fr_FR fr_BE fr_CA gd gl_ES he_IL hi_IN hr hu_HU hy id_ID is_IS it_IT ja ka_GE kk km ko_KR lt_LT lv mk_MK ml_IN mn ms_MY nb_NO nl_NL nl_BE nn_NO pl_PL pt_BR pt_PT ro_RO ru_RU si_LK sk_SK sl_SI sq sr_RS sv_SE sw ta_IN te th tl tr_TR uk ur vi zh_CN zh_TW');
    }

    /**
     * Hyphenated locale codes, used by Craft CMS and Akaunting.
     *
     * Both take the BCP 47 form. Kept as one list because they are the same
     * enumeration — splitting it would mean maintaining the same values twice
     * and letting them diverge.
     *
     * @return list<string>
     */
    public static function hyphenLocales(): array
    {
        return explode(' ', 'en-US en-GB en-AU en-CA ar bg-BG bn-BD bs-BA ca-ES cs-CZ cy-GB da-DK de-DE de-AT de-CH el-GR es-ES es-AR es-CL es-CO es-MX et-EE fa-IR fi-FI fr-FR fr-BE fr-CA he-IL hi-IN hr-HR hu-HU hy-AM id-ID is-IS it-IT ja-JP ka-GE km-KH ko-KR lt-LT lv-LV mk-MK ml-IN ms-MY nb-NO nl-NL nl-BE nn-NO pl-PL pt-BR pt-PT ro-RO ru-RU sk-SK sl-SI sq-AL sr-RS sv-SE sw-KE ta-IN th-TH tr-TR uk-UA ur-PK vi-VN zh-CN zh-TW');
    }

    /**
     * Shape the field schema uses: a value plus the label to show for it.
     *
     * Labels are the codes themselves — right for a list whose values *are*
     * the names people know, like timezone identifiers. Locales and countries
     * are not that: `he_IL` and `bq` tell nobody anything, and they go through
     * the three helpers below instead.
     *
     * @param  list<string>  $values
     * @return list<array{value: string, label: string}>
     */
    public static function asOptions(array $values): array
    {
        return array_map(
            static fn (string $value): array => ['value' => $value, 'label' => $value],
            $values,
        );
    }

    /**
     * Locale codes labelled with their own name — `en_US` → "English (United
     * States)", `pt_BR` → "portugais (Brésil)" for a viewer reading French.
     *
     * This used to hand back the code as its own label, on the reasoning that
     * inventing display names for eighty locales would mean translating those
     * names into eight languages to stay honest. ICU has already done it:
     * `Locale::getDisplayName()` answers in whatever locale it is asked in, so
     * the honest version costs nothing to maintain and nothing to translate.
     *
     * Both code shapes in the catalog work unchanged — WordPress's `en_US` and
     * Craft's `en-US` normalise to the same thing.
     *
     * @param  list<string>  $codes
     * @return list<array{value: string, label: string}>
     */
    public static function localeOptions(array $codes): array
    {
        return self::labelled($codes, static fn (string $code, string $locale): string => \Locale::getDisplayName($code, $locale) ?: $code);
    }

    /**
     * ISO 3166-1 alpha-2 labelled with the country's name — `us` → "United
     * States", `de` → "Allemagne" for a viewer reading French.
     *
     * The dash prefix is what tells ICU to read a bare `us` as a region rather
     * than as a language: `Locale::getDisplayRegion('us')` is empty, while
     * `'-US'` is a locale with a region and no language.
     *
     * @param  list<string>  $codes
     * @return list<array{value: string, label: string}>
     */
    public static function countryOptions(array $codes): array
    {
        return self::labelled($codes, static fn (string $code, string $locale): string => \Locale::getDisplayRegion('-'.strtoupper($code), $locale) ?: $code);
    }

    /**
     * ISO 639-1 labelled with the language's name — `sw` → "Swahili".
     *
     * Separate from {@see localeOptions()} because these carry no region:
     * asking for a display *name* would give "Swahili" either way today, but
     * the two lists mean different things and one of them growing a region
     * should not silently change what the other shows.
     *
     * @param  list<string>  $codes
     * @return list<array{value: string, label: string}>
     */
    public static function languageOptions(array $codes): array
    {
        return self::labelled($codes, static fn (string $code, string $locale): string => \Locale::getDisplayLanguage($code, $locale) ?: $code);
    }

    /**
     * Memoized per locale and per list.
     *
     * `GET /site-types` renders every site type's fields in one response, and
     * the hyphenated locale list alone is asked for by two of them — around
     * two hundred ICU lookups for bytes that are identical both times.
     *
     * @var array<string, list<array{value: string, label: string}>>
     */
    private static array $labelCache = [];

    /**
     * @param  list<string>  $codes
     * @param  callable(string, string): string  $name
     * @return list<array{value: string, label: string}>
     */
    private static function labelled(array $codes, callable $name): array
    {
        // No intl, no names. Falling back to the codes keeps the catalog
        // usable — the alternative is a dropdown of blank rows, which is worse
        // than an unfriendly one and much harder to diagnose.
        if (! extension_loaded('intl')) {
            return self::asOptions($codes);
        }

        $locale = app()->getLocale();
        $key = $locale.'|'.md5(implode(' ', $codes));

        if (isset(self::$labelCache[$key])) {
            return self::$labelCache[$key];
        }

        $options = array_map(
            static fn (string $code): array => ['value' => $code, 'label' => $name($code, $locale)],
            $codes,
        );

        // Sorted by what the user actually reads, in their own language: these
        // lists were hand-ordered by code, and once the label is a name that
        // order looks arbitrary. `Collator` rather than `usort` with strcmp,
        // because alphabetical is a per-language question — ä sorts beside a
        // in German and after z in Swedish, and byte order gets both wrong.
        $collator = new \Collator($locale);
        usort($options, static fn (array $a, array $b): int => (int) $collator->compare($a['label'], $b['label']));

        return self::$labelCache[$key] = array_values($options);
    }

    /**
     * The memo is keyed by locale, so switching locale is already correct.
     * This exists for tests that swap the *list* behind a key — and for a
     * long-lived worker, where "process-wide" is longer than one request.
     */
    public static function flushLabelCache(): void
    {
        self::$labelCache = [];
    }
}
