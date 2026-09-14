/**
 * One English string, one translation — unless there is a reason for two.
 *
 * Key parity proves a locale is complete. ICU shape proves each string is
 * well-formed. Neither can see the defect that 22 translators working in
 * parallel actually produce: the same English word coming back differently on
 * every screen. "Saving…" was "Speichert…" in seven places and "Wird
 * gespeichert…" in twelve. "Memory" was "Arbeitsspeicher" on the dashboard and
 * "Speicher" in the services table — and "Speicher" is also what a German
 * reader calls disk, which this panel shows on the same page. Every one of
 * those passed the build.
 *
 * So: if English uses one string at more than one key, the locale must use one
 * string too. The check is per locale, because a split that is wrong in German
 * may be required in Russian.
 *
 * The exceptions below are not a snag list to work through. They are English
 * strings that genuinely carry more than one meaning, or that other languages
 * must inflect. Adding to it is allowed; doing so without a reason is not,
 * which is why the reason is a required field rather than a comment.
 */

/**
 * English string → why one translation cannot serve every place it is used.
 *
 * Anything not listed here must be rendered identically everywhere.
 *
 * `locales` is the difference between the two reasons a string can be here:
 *
 *   omitted — the ENGLISH carries more than one meaning, so no language can
 *     serve every use with one word. "Right now" is a current value in one
 *     place and the soonest option in another; that is true whoever reads it.
 *
 *   listed — the English is one thing, but these languages must inflect it.
 *     Spanish agrees an adjective with its noun, so a database is "Creada" and
 *     a system user "Creado" — correct, and forcing one would be the bug.
 *     German has no such need for "Created" and must still say it one way, so
 *     the exemption stops at the languages that earn it.
 *
 * A listed exemption still buys a whole string: nothing here checks that
 * "Creada" and "Creado" are the same word inflected rather than two unrelated
 * ones. Narrowing that needs a stemmer per language, which is a lot of
 * machinery to catch a translator who is already ignoring the noun. Keep the
 * lists short and the reasons specific instead.
 */
export const MANY_MEANINGS = {
  "Right now": {
    reason:
      "Three meanings: the current value (swap), the soonest option (reboot), and the live column of a traffic table.",
  },
  After: {
    reason:
      "A column header pairing with Before, and the state following an action. German splits these as Nachher and Danach.",
  },
  Start: {
    reason:
      "The verb on a service button and the noun for a start time. Most languages inflect one and not the other.",
  },
  Custom: {
    reason:
      "An adjective. German, French, Portuguese and Russian must agree it with the noun it qualifies.",
  },
  Other: {
    reason:
      "An adjective qualifying a different noun on each screen — a disk-usage group, a storage provider — so it inflects with each.",
  },
  On: {
    reason:
      "The extensions screen says an extension is active rather than switched on; a firewall rule is on or off. Two vocabularies, one English word.",
  },
  Off: {
    reason: "The other half of the pair above, and split the same way for the same reason.",
  },
  "Always on": {
    reason:
      "Follows whichever of the two vocabularies above its screen uses, so it cannot be fixed to one without breaking one screen.",
  },
  Time: {
    reason: "A chart axis and a clock time. German splits these as Zeit and Uhrzeit.",
  },
  "Measuring…": {
    reason:
      "One sits in the applications table's Size column, which is a percentage width with nowrap text: at 1024px it has 66px and German's consistent form needs 108px. The dashboard's copy has room.",
  },

  // Adjectives and participles. Spanish agrees them with the noun, and Hindi
  // agrees them with the subject's gender, so the rendering changes with what
  // is being described — a database (feminine) against a system user
  // (masculine), one cron job against a filter over many.
  Created: {
    reason:
      "A participle agreeing with what was created: a database is feminine, a system user masculine.",
    locales: ["es"],
  },
  Connected: {
    reason:
      "A participle describing either the Central connection or a Git repository, which differ in gender.",
    locales: ["es"],
  },
  Active: {
    reason:
      "A badge on one cron job and a filter over many of them, so it agrees in number as well as gender.",
    locales: ["es"],
  },
  All: {
    reason:
      "A quantifier agreeing with whatever it counts — severities, extensions, sites — each a different gender.",
    locales: ["es"],
  },
  Added: {
    reason: "A participle agreeing with the thing added: a firewall rule against a quick-add tile.",
    locales: ["es"],
  },
  Installed: {
    reason: "A participle agreeing with a PHP version (feminine) or a setup component (masculine).",
    locales: ["es"],
  },
  Ready: {
    reason: "An adjective agreeing with a database export (feminine) or an application (masculine).",
    locales: ["es"],
  },
  '"{name}" created.': {
    reason:
      "The name is a file or a folder, and the participle agrees with whichever the caller made.",
    locales: ["es", "hi"],
  },
  Protected: {
    reason: "An adjective describing one site or a filter over several, so it agrees in number.",
    locales: ["es"],
  },
  Recommended: {
    reason: "A section heading over several items and a badge on one, so it agrees in number.",
    locales: ["es"],
  },
  Paused: {
    reason:
      "A state shared by a site, a cron job and a backup schedule, which differ in gender; the Hindi participle agrees with the subject.",
    locales: ["es", "hi"],
  },
  Running: {
    reason:
      "A state shared by a service, a backup and a fail2ban daemon; the Hindi participle agrees with the subject's gender.",
    locales: ["hi"],
  },
  "Starting…": {
    reason: "A progress label whose Hindi participle agrees with what is starting.",
    locales: ["hi"],
  },
  "Started {when}": {
    reason: "A progress label whose Hindi participle agrees with what started.",
    locales: ["hi"],
  },
  "Showing {shown} of {total}": {
    reason: "The Hindi verb agrees with the gender of the rows being counted.",
    locales: ["hi"],
  },
  Enabled: {
    reason:
      "One participle agreeing with its subject: a worker or a toggle is Activado, a firewall rule Activa.",
    locales: ["es"],
  },
  Disabled: {
    reason: "The other half of the pair above, agreeing for the same reason.",
    locales: ["es"],
  },
  Failed: {
    reason:
      "A status badge on a specific thing — un servicio, una copia, varias copias — so the participle agrees with it. It is one word: Spanish said this seven ways before, including two that were not fallido at all.",
    locales: ["es"],
  },
  Complete: {
    reason: "A badge on one backup and a count of several, so it agrees in number.",
    locales: ["es"],
  },
};

/*
 * There was a KNOWN_SPLITS pin here. Spanish and Hindi were translated the same
 * way German was — in parallel, with no shared glossary — and shipped before
 * anything could see it, so the check was written against 60 and 108 existing
 * splits and held them from growing while they waited to be fixed.
 *
 * They are fixed. Every locale is now held at zero, which is the only state
 * that stays true on its own: a pin has to be lowered by hand, and one nobody
 * lowers reads as a clean bill of health.
 */

/**
 * Problems for one locale, or [].
 *
 * `english` and `translated` are flat maps of dotted key → string. `locale`
 * decides whether a scoped exemption applies; a caller that does not say which
 * language it is holding gets only the unscoped ones, because a pass granted to
 * Spanish is not a pass for whoever is asking.
 */
export function oneVoiceProblems(english, translated, locale) {
  const keysByEnglish = new Map();
  for (const [key, source] of Object.entries(english)) {
    if (typeof source !== "string" || !source.trim()) continue;
    if (!keysByEnglish.has(source)) keysByEnglish.set(source, []);
    keysByEnglish.get(source).push(key);
  }

  const problems = [];

  for (const [source, keys] of keysByEnglish) {
    if (keys.length < 2) continue;
    const exempt = MANY_MEANINGS[source];
    if (exempt && (!exempt.locales || exempt.locales.includes(locale))) continue;

    const byRendering = new Map();
    for (const key of keys) {
      const rendering = translated[key];
      if (typeof rendering !== "string") continue;
      if (!byRendering.has(rendering)) byRendering.set(rendering, []);
      byRendering.get(rendering).push(key);
    }
    if (byRendering.size < 2) continue;

    const shown = [...byRendering]
      .sort((a, b) => b[1].length - a[1].length)
      .map(([rendering, at]) => `"${rendering}" (${at.length}× e.g. ${at[0]})`)
      .join(" vs ");
    problems.push(
      `"${source}" is translated ${byRendering.size} ways — ${shown}. ` +
        "Pick one, or add it to MANY_MEANINGS in scripts/one-voice.mjs with the reason.",
    );
  }

  return problems;
}
