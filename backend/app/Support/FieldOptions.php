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
     * Labels are the codes themselves. They are what the installer's own
     * documentation uses, so a user copying a value from WordPress's docs can
     * find it — and inventing display names for 80 locales would mean
     * translating those names into 8 languages to stay honest.
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
}
