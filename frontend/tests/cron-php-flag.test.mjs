import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { phpMismatch } from "../lib/cron-jobs/php-mismatch.js";
import { cronjobSchema } from "../lib/schemas/cronjob.js";

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/\/\/.*$/gm, "");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const job = { id: 6, name: "Site pinned", username: "qa", command: "/usr/bin/php8.3 -f x.php", expression: "0 4 * * *", active: true };

// a338f771: every cron says which PHP it runs and, for a site's job, whether that is the site's.
test("the list keeps application_id and the two PHP facts (they were stripped)", () => {
  const row = cronjobSchema.parse({ ...job, application_id: 1, php_version: "8.3", matches_site_php: false });
  assert.deepEqual([row.application_id, row.php_version, row.matches_site_php], [1, "8.3", false]);
  assert.equal(cronjobSchema.parse(job).matches_site_php, undefined);
});

test("only a site's job that is known not to match is flagged; the target comes from the applications list", () => {
  const apps = [{ id: 1, php_version: "8.4" }];
  assert.deepEqual(phpMismatch({ ...job, application_id: 1, php_version: "8.3", matches_site_php: false }, apps), { current: "8.3", target: "8.4" });
  assert.deepEqual(phpMismatch({ ...job, application_id: 1, php_version: "default", matches_site_php: false }, []), { current: "default", target: null });
  for (const quiet of [{ application_id: 1, matches_site_php: true }, { application_id: 1, matches_site_php: null }, { application_id: null, matches_site_php: false }]) {
    assert.equal(phpMismatch({ ...job, ...quiet }, apps), null, JSON.stringify(quiet));
  }
});

test("Switch is offered only with both versions known, behind a confirm, through the site's switch", () => {
  const actions = strip(read("components/cron-jobs/cronjob-row-actions.jsx"));
  assert.match(actions, /const switchTo = mismatch\?\.current && mismatch\.target \? mismatch\.target : null;/);
  assert.match(actions, /\{switchTo \? \(\s*<MenuItemHint hint=\{canManage \? null : t\("noPermission"\)\}>/);
  const dialog = strip(read("components/cron-jobs/switch-php-dialog.jsx"));
  assert.match(dialog, /switchApplicationCronjobsPhp\(job\.application_id, \{ version, cronjobIds: \[job\.id\] \}\)/);
  assert.match(dialog, /setError\(apiMessage\(err, t\("phpMismatch\.failed"\)\)\);/);
  for (const p of ["components/cron-jobs/cronjobs-table.jsx", "components/cron-jobs/cronjobs-cards.jsx"]) assert.match(read(p), /<CronjobPhpNote job=/, p);
  // Table cells are nowrap: unwrapped, the note ran into the Active switch (Krishna, 28 Oct).
  assert.match(read("components/cron-jobs/cronjob-php-note.jsx"), /whitespace-normal/);
});

test("the phone card puts each label on top, in two columns, so a long label never squeezes its value", () => {
  const cards = read("components/cron-jobs/cronjobs-cards.jsx");
  assert.match(cards, /<CardFactGrid>/);
  assert.equal((cards.match(/<CardFactStacked label=/g) ?? []).length, 5);
  assert.doesNotMatch(cards, /<CardFact label=/);
  assert.match(read("components/data-table/card-list.jsx"), /export function CardFactGrid[\s\S]*?grid grid-cols-2 gap-x-4 gap-y-3/);
});

test("every new string exists in all 8 languages", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`)).cronJobs.phpMismatch;
    for (const key of ["fromVersion", "fromDefault", "unknownTarget", "switchAction", "switchTitle", "switchDescription", "switched", "failed"]) assert.ok(m?.[key], `${locale}: ${key}`);
  }
});
