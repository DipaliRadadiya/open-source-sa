import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Relabelling a site. The backend was complete — two endpoints and a field on
 * every application payload — and the frontend referenced none of it.
 *
 * The rules are the backend's and it re-makes every one of them at apply time,
 * so nothing here is a guard. What is pinned is the handful of places where
 * getting the SCREEN wrong would mislead someone about what the button does.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) =>
  s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/^\s*\/\/.*$/gm, "");

const lib = read("lib/applications/site-type-detection.js");
const card = read("components/applications/site-facts-card.jsx");
const dialog = read("components/applications/site-type-relabel-dialog.jsx");
const schema = read("lib/schemas/application.js");
const apiFile = read("lib/api/applications.js");
const cardCode = strip(card);
const dialogCode = strip(dialog);
const { locales } = await import("../i18n/routing.js");

test("the payload field is declared, or Zod silently eats the feature", () => {
  // Same class as `url` and `disk_io`: the API sends it, the schema drops it,
  // nothing errors and the screen just never has the data.
  assert.match(schema, /site_type_detection: z\s*\n?\s*\.object\(\{/);
  for (const field of ["detected", "detected_title", "confidence", "matched", "checked_at", "suggested"]) {
    assert.match(schema, new RegExp(`${field}: z\\.`), `schema is missing ${field}`);
  }
});

test("detection is never automatic", () => {
  /*
   * The backend's reason, not a preference: a site's files arrive AFTER it is
   * created, so probing on mount would run against an empty directory and
   * record "nothing found" at the one moment that answer is guaranteed wrong.
   * Plesk's WP Toolkit and Softaculous both make you press a button too.
   */
  assert.doesNotMatch(cardCode, /useEffect\([^)]*detect/s, "detection must not run on mount");
  // The probe is a menu item now, not the tile's primary control — that seat
  // belongs to a suggestion, which is the only thing here worth interrupting
  // for. Either way it is reached by a click.
  assert.match(cardCode, /key: "detect",[\s\S]{0,160}onSelect: detect,/);
  assert.match(cardCode, /onClick: \(\) => setRelabelTo\(suggestion\)/);
});

test("the probe button is disabled while in flight", () => {
  // Both endpoints are throttle:10,1. A double-click is the realistic way to
  // spend that, and the Fact action already disables on `busy`.
  assert.match(cardCode, /busy: detecting/);
  assert.match(cardCode, /setDetecting\(true\)/);
  assert.match(cardCode, /setDetecting\(false\)/);
});

test("a git site is offered nothing at all", () => {
  // The backend refuses to probe one, and its type can never change in either
  // direction — so every verdict would be a finding nothing may act on.
  assert.match(lib, /String\(application\?\.site_type\) === "git"/);
  assert.match(lib, /export function canDetectSiteType/);
  assert.match(lib, /return !isGitSite\(application\)/);
  // One gate, on the menu that holds every type action — so a git site gets
  // neither the probe nor a relabel target.
  assert.match(cardCode, /if \(!canManage \|\| !canDetectSiteType\(application\)\) return \[\];/);
  // And narrowing refuses independently, so a caller that skipped the menu
  // still cannot offer one.
  assert.match(lib, /export function narrowingTargets[\s\S]{0,200}if \(!canDetectSiteType\(application\)\) return \[\];/);
});

test("a relabelled site can be set back — the dialog promises it", () => {
  /*
   * Reported: "you are showing that you can set it back to Custom PHP at any
   * time. is it really possible and available?"
   *
   * It was possible through the API and NOT available in the panel — nothing
   * offered the reverse, so the reassurance was a promise the product did not
   * keep. Either the sentence went or the control arrived; the control
   * arrived, because a change that feels permanent is exactly when the
   * reassurance earns its place.
   */
  assert.match(cardCode, /for \(const name of narrowingTargets\(application\)\)/);
  assert.match(cardCode, /label: t\("siteTypeDetection\.changeTo", \{ type: title \}\)/);
  assert.match(cardCode, /onSelect: \(\) => setRelabelTo\(name\)/);

  // Titles come from the catalog. Without one the target is dropped rather
  // than listed by its internal name ("php").
  assert.match(cardCode, /const title = siteTypes\.find\(\(type\) => type\.name === name\)\?\.title;/);
  assert.match(cardCode, /if \(!title\) continue;/);
});

test("a hand-picked narrowing claims no evidence", () => {
  // `matched` belongs to the suggestion. Naming a file on a narrowing would
  // imply the disk was consulted, when narrowing needs no evidence at all.
  assert.match(cardCode, /matched=\{relabelTo === suggestion \? detection\?\.matched : null\}/);
});

test("the suggestion comes from the API, never re-derived", () => {
  /*
   * `suggested` is pre-validated against every refusal the apply endpoint
   * would make. Recomputing it from `detected` + `confidence` would reimplement
   * four gates — git, unchanged, min-confidence, and generic→suggestable — and
   * they would drift out of agreement with the endpoint.
   */
  assert.match(lib, /return application\?\.site_type_detection\?\.suggested \?\? null;/);
  assert.doesNotMatch(strip(lib), /confidence\s*[<>]=?\s*\d/, "confidence must not be re-thresholded here");
  assert.doesNotMatch(strip(lib), /min_confidence|60/, "the floor is the backend's");
});

test("a probe that found nothing says so", () => {
  /*
   * The reason this feature needed research. Softaculous's support board has
   * recurring threads titled "Scan says no installations found" — a probe that
   * reports nothing and does not SAY so is indistinguishable from a button
   * that did not work.
   */
  assert.match(cardCode, /detectionState === "found"\) return t\("siteTypeDetection\.nothingFound"\)/);
  // Keyed on `checked_at`, the only field that proves a probe ran: `detected`
  // is null both before the first probe and after a fruitless one, so keying
  // on it would tell someone who never pressed the button that nothing was
  // found.
  assert.match(lib, /if \(application\?\.site_type_detection\?\.checked_at\) return "found";/);
});

test("the note names the file, not the confidence score", () => {
  // `matched` exists so the note can say WHY. "wp-config.php found" is
  // checkable; "confidence 95" is a number nobody can act on.
  assert.match(cardCode, /looksLikeBecause/);
  assert.match(cardCode, /file: detection\.matched/);
  assert.doesNotMatch(cardCode, /detection\.confidence/, "the score is a gate, not information");
});

test("the dialog answers both questions, not just the scary one", () => {
  /*
   * From UpdateSiteTypeRequest: "This changes what the panel offers, not what
   * is on disk… the opposite assumption — that changing the type converts the
   * site — is the natural one." So the correction must be in front of the
   * button.
   *
   * But the FIRST version was only the correction — one amber warning saying
   * what would not happen — which left nothing on screen explaining why anyone
   * would press the button. Reported as confusing, fairly. Both halves now,
   * each labelled with the question it answers.
   */
  assert.match(dialogCode, /label=\{t\("changesLabel"\)\}/);
  assert.match(dialogCode, /label=\{t\("unchangedLabel"\)\}/);
  assert.match(dialogCode, /\{t\("changesBody", \{ type: targetTitle \}\)\}/);

  const copy = JSON.parse(read("messages/en.json")).applications.siteTypeDetection;
  // The gain has to be stated, or the correction reads as "this does nothing".
  assert.match(copy.changesBody, /screens/i);
  // And the correction has to survive.
  assert.match(copy.unchangedBody, /Nothing is installed/i);
  assert.match(copy.unchangedBody, /files/i);
  assert.ok(!("notAnInstall" in copy), "the superseded paragraph should be gone, not orphaned");
});

test("reversibility is promised only where the API actually keeps it", () => {
  /*
   * Narrowing back to a generic type is always allowed and needs no evidence —
   * the request returns early for it. So "you can set it back" is true for a
   * widening. On a narrowing there is nothing to undo that the sentence would
   * describe correctly, so it is not shown.
   */
  assert.match(dialogCode, /\{matched \? \(\s*\n?\s*<p className="text-xs text-muted-foreground">\s*\n?\s*\{t\("reversible"/);
});

test("applying re-reads the route rather than patching a field", () => {
  // The type decides which screens a site has — WordPress adds Staging, Clone
  // and Magic Login — so the sidebar changes. A local patch would leave the
  // rail claiming the old set.
  assert.match(dialogCode, /router\.refresh\(\)/);
  assert.doesNotMatch(dialogCode, /setApplication|mutate\(/);
});

test("a refusal is shown verbatim, in the dialog", () => {
  /*
   * Six refusal strings already exist server-side and are better than anything
   * written here — the git one explains that relabelling would hide the
   * Deployments and Workers screens without stopping the workers or the deploy
   * webhook. A toast clears in four seconds; this stays.
   */
  assert.match(dialogCode, /setError\(apiMessage\(err, t\("failed"\)\)\)/);
  assert.match(dialogCode, /error=\{error\}/);
});

test("stale errors cannot survive into the next attempt", () => {
  // This dialog is opened by a button that sets `target` directly, which skips
  // onOpenChange entirely.
  assert.match(dialogCode, /if \(!next\) setError\(null\)/);
});

test("the API helpers hit the real endpoints", () => {
  assert.match(apiFile, /api\.post\(`\/applications\/\$\{id\}\/detect-type`\)/);
  assert.match(apiFile, /api\.put\(`\/applications\/\$\{id\}\/site-type`, \{ site_type: siteType \}\)/);
});

test("the generic list is quarantined in one named place", () => {
  /*
   * It mirrors config('server.site_type_detection.generic') because the
   * site-types catalog does not expose `generic` or `suggestable` — filed as a
   * backend ask. One constant, flagged, so there is a single thing to delete
   * when the API carries the flag.
   */
  assert.match(lib, /export const GENERIC_SITE_TYPES = \["php", "static"\]/);
  assert.match(lib, /DUPLICATED/);
  const others = [card, dialog].map(strip).join("\n");
  assert.doesNotMatch(others, /"php", "static"|\["static"/, "the list must not be copied into components");
});

test("every string is translated in all eight locales", () => {
  const en = JSON.parse(read("messages/en.json"));
  const keys = Object.keys(en.applications.siteTypeDetection);
  assert.ok(keys.length >= 13, `expected the full set, found ${keys.length}`);
  for (const locale of locales) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    const block = m.applications?.siteTypeDetection;
    assert.ok(block, `${locale} has no siteTypeDetection block`);
    for (const key of keys) {
      assert.ok(block[key], `${locale} is missing ${key}`);
      if (locale !== "en") {
        // Placeholders are what breaks a translation at render time.
        const expected = (en.applications.siteTypeDetection[key].match(/\{\w+\}/g) ?? []).sort();
        const actual = (block[key].match(/\{\w+\}/g) ?? []).sort();
        assert.deepEqual(actual, expected, `${locale}.${key} placeholders differ`);
      }
    }
  }
});
