// An English string used at several keys must be translated one way in each locale. Exceptions below need a reason.

// English string → why it may be translated differently. locales omitted: the English has several meanings. Listed: only those languages need to inflect it.
export const MANY_MEANINGS = {
  "Last run": {
    locales: ["es"],
    reason:
      "A backup's last copy made (Última copia) and a cron job's last execution (Última ejecución) — different nouns in Spanish.",
  },
  "Running now": {
    locales: ["hi"],
    reason:
      "One cron job running (अभी चल रहा है) and a count of database threads running (अभी चल रहे): Hindi agrees the verb with the number.",
  },
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
  Runs: {
    locales: ["es", "de", "ja", "ru", "hi"],
    reason:
      "A schedule's run time and a recipe's list of services it executes. Russian names the time in one and the execution verb in the other; these locales likewise need distinct labels.",
  },
  Stopped: {
    locales: ["es", "fr", "pt"],
    reason:
      "A state agreeing with its noun: a service (masculine) and an application (feminine: aplicación, application, aplicação).",
  },
  Custom: {
    reason:
      "An adjective. German, French, Portuguese and Russian must agree it with the noun it qualifies.",
  },
  System: {
    locales: ["ru"],
    reason:
      "The noun for a system-owned record, and an adjective on the theme menu qualifying тема. Russian inflects the second (Системная) and not the first.",
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
    locales: ["es", "fr", "pt"],
  },
  Ready: {
    reason: "An adjective agreeing with a database export (feminine) or an application (masculine).",
    locales: ["es"],
  },
  '"{name}" created.': {
    reason:
      "The name is a file or a folder, and the participle agrees with whichever the caller made.",
    locales: ["es", "hi", "pt"],
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
    locales: ["es", "pt"],
  },
  Disabled: {
    reason: "The other half of the pair above, agreeing for the same reason.",
    locales: ["es", "pt"],
  },
  Failed: {
    reason:
      "A status badge on a specific thing — un servicio, una copia, varias copias — so the participle agrees with it. It is one word: Spanish said this seven ways before, including two that were not fallido at all.",
    locales: ["es"],
  },
  Complete: {
    reason: "A badge on one backup and a count of several, so it agrees in number.",
    locales: ["es", "pt"],
  },
};

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
