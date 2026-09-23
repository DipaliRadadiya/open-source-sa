import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { lineLevel, matchesSeverity } from "../lib/logs/severity.js";

/*
 * Batch B: controls that were enabled, clickable, and could not work — or that
 * came pre-filled with the one value guaranteed to fail. Batch A was the panel
 * saying untrue things; this is the panel offering actions it cannot perform.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

/* ---------------------------------------------------------------------------
 * Bulk move/copy started at the one destination that always fails
 * ------------------------------------------------------------------------ */

test("move and copy do not pre-fill the folder the files are already in", () => {
  /*
   * All three bulk actions started at `path`. For compress that is right — the
   * archive lands beside its contents. For move and copy it is the single
   * destination guaranteed to fail: select twelve files, press Move, press
   * Confirm, and every one comes back "something is already at the
   * destination".
   */
  const bulk = read("components/applications/files/bulk-dialogs.jsx");
  // Beside its contents, under a name nothing there already has.
  assert.match(bulk, /action === "compress" \? compressSuggestion\(joinPath\(path, "archive"\), "\.zip", new Set\(files\.map\(\(f\) => f\.path\)\)\) : ""/);

  const code = strip(bulk);
  assert.doesNotMatch(
    code,
    /action === "compress" \? joinPath\(path, "archive\.zip"\) : path/,
    "move and copy are pre-filled with the current folder again",
  );

  // The guard that makes an empty field safe already existed — it was simply
  // unreachable behind the pre-filled value. It has to stay.
  //
  // The expression now also covers Permissions, which grew the same problem
  // later: a mixed selection starts with no mode chosen and must not be
  // saveable untouched. Move and copy keep exactly the behaviour this test was
  // written for — an empty target still disables Save.
  assert.match(bulk, /disabled=\{busy \|\| \(isPermissions \? !mode : !target\.trim\(\)\)\}/);
  assert.match(bulk, /!isPermissions && !target\.trim\(\)\s*\n?\s*\? tc\("enterAValue"\)/);
});

/* ---------------------------------------------------------------------------
 * Deploy-on-push could not be re-enabled after relinking the Git account
 * ------------------------------------------------------------------------ */

test("a webhook counts as configured only when it has a provider", () => {
  /*
   * Relinking clears `webhook_provider` and `webhook_secret` but deliberately
   * KEEPS `webhook_identifier`, from which `webhook.url` is derived. Treating
   * that surviving URL as configuration made the card render the on/off switch
   * instead of the setup form, and flipping it posted `provider: null`, which
   * the API rejects with `required_if`. Error toast every time, no route back.
   *
   * A URL is an address. It is not configuration.
   */
  const card = read("components/applications/deployment/webhook-card.jsx");
  assert.match(card, /const configured = Boolean\(webhook\.provider\)/);

  const code = strip(card);
  assert.doesNotMatch(
    code,
    /Boolean\(webhook\.url \|\| webhook\.provider\)/,
    "a leftover delivery URL counts as configuration again",
  );
});

/* ---------------------------------------------------------------------------
 * Bot blocker "Add" was enabled with an empty box
 * ------------------------------------------------------------------------ */

test("bot blocker Add is unavailable until something is typed", () => {
  /*
   * `add()` returns silently on an empty value, so the button was enabled,
   * clickable, and did nothing at all — no entry, no error, not even focus
   * back in the field. The firewall's identical control has always guarded on
   * `!draft.trim()`; this is the same expression.
   */
  const bot = read("components/applications/bot-blocker/bot-blocker-section.jsx");
  assert.match(bot, /onClick=\{add\}\s*\n\s*disabled=\{disabled \|\| !draft\.trim\(\)\}/);

  // The sibling that already did it right must not regress either.
  const firewall = read("components/applications/firewall/rule-list.jsx");
  assert.match(firewall, /disabled=\{disabled \|\| full \|\| !draft\.trim\(\)\}/);
});

/* ---------------------------------------------------------------------------
 * The firewall detect log's severity filter matched nothing
 * ------------------------------------------------------------------------ */

test("the detect log is graded as web format, because that is what it is", () => {
  /*
   * `waf_detect` is written by the web server in `combined` — byte for byte the
   * access log's shape, as parse-detect-log.js states outright. It was grouped
   * as "system", so `lineLevel` skipped HTTP-status parsing and looked for
   * words like "error" that a combined line never contains. Every line graded
   * as null, so Errors and Warnings emptied the tab.
   */
  const panel = read("components/applications/logs/application-logs-panel.jsx");
  assert.match(panel, /const WEB_FORMAT_KEYS = new Set\(\["access", "waf_detect"\]\)/);

  // The behaviour, not just the constant — a real combined line, both ways.
  const line =
    '1.2.3.4 - - [13/Aug/2026:05:12:33 +0000] "GET /wp-admin?a=b HTTP/1.1" 403 512 "-" "curl/8"';

  assert.equal(lineLevel(line, "system"), null, "the old grouping graded it as nothing");
  assert.equal(lineLevel(line, "web"), "warn");
  assert.equal(matchesSeverity(line, "web", "warnings"), true);
  assert.equal(matchesSeverity(line, "system", "warnings"), false);

  // A 5xx must reach the Errors bucket, or the filter is only half alive.
  const failing = line.replace(" 403 ", " 502 ");
  assert.equal(lineLevel(failing, "web"), "error");
  assert.equal(matchesSeverity(failing, "web", "errors"), true);
});

/* ---------------------------------------------------------------------------
 * "Folder size" measured, stored the answer, and had nowhere to show it
 * ------------------------------------------------------------------------ */

test("folder size reaches the phone layout, not just the table", () => {
  /*
   * The action ran and the result landed in state, but `folderSizes` was only
   * ever passed to the desktop table. On a phone the ⋯ menu closed and nothing
   * happened, ever.
   */
  const panel = read("components/applications/files/files-panel.jsx");
  const cards = read("components/applications/files/files-cards.jsx");

  // The panel hands both pieces of state to the card list.
  const cardsBlock = panel.slice(panel.indexOf("<FilesCards"), panel.indexOf("<FilesTable"));
  assert.match(cardsBlock, /folderSizes=\{folderSizes\}/);
  assert.match(cardsBlock, /sizingPaths=\{sizingPaths\}/);

  // And the card renders it, with in-progress feedback.
  assert.match(cards, /folderSizes = \{\},/);
  assert.match(cards, /sizingPaths = \[\],/);
  assert.match(cards, /const measuring = sizingPaths\.includes\(file\.path\)/);
  assert.match(cards, /measuring \? \(\s*tSize\("measuring"\)/);
  assert.match(cards, /file\.type === "dir" \? folderSizes\[file\.path\] : file\.size_human/);
});

test("the measuring label reuses the string that already existed", () => {
  // `applications.size.measuring` is already shared with the dashboard. A
  // files-scoped copy would be a second string for one sentence.
  const cards = read("components/applications/files/files-cards.jsx");
  assert.match(cards, /useTranslations\("applications\.size"\)/);

  const en = JSON.parse(read("messages/en.json"));
  assert.ok(en.applications.size.measuring, "the shared string is gone");
  assert.equal(
    en.applications.files.size?.measuring,
    undefined,
    "a duplicate files-scoped copy was added",
  );
});
