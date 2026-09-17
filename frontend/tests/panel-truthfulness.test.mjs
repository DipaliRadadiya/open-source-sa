import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { addDomainFormSchema } from "../lib/schemas/domain.js";

/*
 * Batch A of a panel-wide audit: five places where the UI stated something
 * that was not true. Not five cosmetic bugs — a control that visibly does
 * nothing is annoying, but a screen that confidently says the wrong thing gets
 * believed and acted on.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);

const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]),
);

/* ---------------------------------------------------------------------------
 * 1 — restoring a .env said the running app had the restored values
 * ------------------------------------------------------------------------ */

test("both doors to an env restore offer the same choice", () => {
  /*
   * The editor's dialog has always had a restart checkbox. The history card —
   * same endpoint, same action — sent no flag and told the reader "The
   * application keeps running with the restored values." On a Node site the
   * process kept running with the OLD ones.
   */
  const history = read("components/applications/environment/environment-history-card.jsx");

  assert.match(history, /restoreEnvironment\(appId, \{ backup: pending\.backup, restart \}\)/);
  assert.match(history, /requiresRestart = false/);
  assert.match(history, /tEnv\("restore\.restart"\)/, "the two dialogs must share one wording");

  // And the page has to actually supply the signal, or the box never appears.
  const page = read("app/(app)/applications/[application]/environment/page.jsx");
  assert.match(page, /requiresRestart=\{envResult\.environment\.requires_restart\}/);
});

test("the confirm no longer claims the app is already running the restored values", () => {
  for (const l of LOCALES) {
    const body = messages[l].applications.environment.history.confirmBody;
    assert.ok(body, `${l} lost history.confirmBody`);
  }
  // The English claim, gone. It is the sentence that was false.
  assert.doesNotMatch(
    messages.en.applications.environment.history.confirmBody,
    /keeps running with the restored values/i,
  );
  // The true half — it is undoable — is kept.
  assert.match(messages.en.applications.environment.history.confirmBody, /backed up first/i);

  // Ticking the box and then abandoning the dialog must not carry over.
  const history = read("components/applications/environment/environment-history-card.jsx");
  assert.match(history, /setPending\(null\);\s*[\s\S]{0,200}?setRestart\(false\);/);
});

/* ---------------------------------------------------------------------------
 * 2 — a folder of dotfiles said it was empty
 * ------------------------------------------------------------------------ */

test("a folder holding only hidden files does not claim to be empty", () => {
  const panel = read("components/applications/files/files-panel.jsx");

  assert.match(panel, /files\.length === 0 && !showHidden && hiddenCount > 0/);
  assert.match(panel, /empty\.hiddenOnlyTitle/);
  // The way out is offered, and it reuses the toggle's own href so there is one
  // definition of "show hidden" on the page.
  assert.match(panel, /href=\{hiddenHref\}/);

  // The branch must come BEFORE the plain empty state or it can never run.
  const hiddenAt = panel.indexOf("hiddenOnlyTitle");
  const emptyAt = panel.indexOf('t("empty.title")');
  assert.ok(hiddenAt < emptyAt, "the generic empty state shadows the hidden-only one");
});

test("the hidden-only copy counts correctly in every language", () => {
  for (const l of LOCALES) {
    const empty = messages[l].applications.files.empty;
    assert.ok(empty.hiddenOnlyTitle, `${l} is missing empty.hiddenOnlyTitle`);
    assert.ok(empty.hiddenOnlyDescription, `${l} is missing empty.hiddenOnlyDescription`);
    // It names a number, so it has to be a plural form, not a fixed sentence.
    assert.match(empty.hiddenOnlyTitle, /\{count, plural,/, `${l} is not pluralised`);
  }
});

/* ---------------------------------------------------------------------------
 * 3 — "0 files" when the measurement had failed
 * ------------------------------------------------------------------------ */

test("a failed measurement is not reported as an empty folder", () => {
  /*
   * `getBreakdown` returns null on a non-ok response, a schema mismatch or a
   * throw, and its docblock says that null exists "so the card can say it
   * could not measure, which is a different sentence from 'this folder is
   * empty'". Null is falsy, so it satisfied neither branch and fell through to
   * the ordinary subtitle — a folder of gigabytes reading as "0 files".
   */
  const sheet = read("components/applications/files/size-breakdown-sheet.jsx");
  assert.match(sheet, /const unavailable = !breakdown \|\| breakdown\.available === false/);

  const code = strip(sheet);
  assert.doesNotMatch(
    code,
    /const unavailable = breakdown && breakdown\.available === false/,
    "null falls through to the subtitle again",
  );
});

test("the two not-measurable reasons say different things", () => {
  /*
   * `available: false` is the backend reporting the walk was too big — a true,
   * specific cause. A null is the request not arriving, where "too large"
   * would be a confident guess at a reason we do not have. Naming the wrong
   * cause is the same class of bug as the rest of this batch.
   */
  const sheet = read("components/applications/files/size-breakdown-sheet.jsx");
  assert.match(sheet, /const unavailableMessage = breakdown \? t\("unavailable"\) : t\("measureFailed"\)/);

  for (const l of LOCALES) {
    const b = messages[l].applications.files.breakdown;
    assert.ok(b.measureFailed, `${l} is missing breakdown.measureFailed`);
    assert.notEqual(b.measureFailed, b.unavailable, `${l} gives both reasons one sentence`);
  }
  assert.doesNotMatch(messages.en.applications.files.breakdown.measureFailed, /too large/i);
});

/* ---------------------------------------------------------------------------
 * 4 — an https link into a certificate warning
 * ------------------------------------------------------------------------ */

test("the open-site link is https only when the certificate covers that name", () => {
  const section = read("components/applications/domains/domains-section.jsx");

  assert.match(section, /function coveredByCertificate\(certificate, domain\)/);
  assert.match(section, /secured && coveredByCertificate\(certificate, domain\.domain\)/);

  /*
   * Keyed off `missing_domains`, not the positive `domains` list. If the
   * backend has not computed coverage, an empty `missing_domains` leaves every
   * link as it is today; an empty `domains` would downgrade every site to
   * http. It also avoids re-implementing wildcard matching in the frontend.
   */
  assert.match(section, /!certificate\?\.missing_domains\?\.includes\(domain\)/);

  const code = strip(section);
  assert.doesNotMatch(
    code,
    /\$\{secured \? "https" : "http"\}/,
    "back to the site-wide flag, which ignores per-domain coverage",
  );
});

/* ---------------------------------------------------------------------------
 * 5 — a valid hostname rejected for being typed in capitals
 * ------------------------------------------------------------------------ */

test("capitals are accepted and normalised, the way the backend stores them", () => {
  /*
   * Hostnames are case-insensitive and the backend already does
   * `strtolower(trim(...))` before validating, so `Example.com` was always
   * going to be accepted and stored lowercased. Only this regex refused it,
   * with "Enter a valid hostname" — wrong, and unactionable, because the name
   * IS valid.
   */
  const parse = (domain) =>
    addDomainFormSchema.safeParse({
      domain,
      type: "alias",
      redirect_to: "",
      redirect_status: 301,
    });

  for (const input of ["Example.com", "EXAMPLE.COM", "  Example.Com  "]) {
    const result = parse(input);
    assert.ok(result.success, `${input} was rejected`);
    assert.equal(result.data.domain, "example.com", `${input} was not normalised`);
  }

  // Normalising must not turn the check off.
  for (const bad of ["bad_host", "-nope.com", "no-dot", "", "exa mple.com"]) {
    assert.equal(parse(bad).success, false, `${JSON.stringify(bad)} was accepted`);
  }
});
