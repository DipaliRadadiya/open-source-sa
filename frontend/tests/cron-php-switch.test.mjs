import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { cronjobsOnDefaultSchema, cronjobsToReviewSchema } from "../lib/schemas/cronjob-php.js";

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/\/\/.*$/gm, "");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const review = strip(read("components/applications/php/cron-php-review.jsx"));
const panel = strip(read("components/applications/php/php-panel.jsx"));

// a338f771 (#5): a site's PHP save lists the cron jobs left on another PHP and changes none.
test("the review list keeps what the dialog needs, and a list it cannot read offers nothing", () => {
  const jobs = cronjobsToReviewSchema.parse([
    { id: 12, name: "Shop background jobs #4", command: "/usr/bin/php8.3 -f /home/shop/wp-cron.php", php_version: "8.3", suggested: true, new_command: "/usr/bin/php8.4 -f /home/shop/wp-cron.php" },
    { id: 15, name: "Scheduler", command: "php artisan schedule:run", php_version: "default", suggested: false, new_command: "/usr/bin/php8.4 artisan schedule:run" },
  ]);
  assert.deepEqual(jobs.map((job) => [job.id, job.suggested]), [[12, true], [15, false]]);
  // The PHP save already succeeded; a malformed list must not turn it into an error.
  assert.deepEqual(cronjobsToReviewSchema.parse([{ id: "x" }]), []);
  assert.deepEqual(cronjobsToReviewSchema.parse("nope"), []);
  assert.equal(cronjobsOnDefaultSchema.parse([{ id: 3, name: "Laravel scheduler", command: "php artisan schedule:run", application_id: 7 }]).length, 1);
});

test("switching sends only the ticked ids for the saved version", () => {
  assert.match(read("lib/api/applications.js"), /api\.post\(`\/applications\/\$\{id\}\/cronjobs\/switch-php`, \{ version, cronjob_ids: cronjobIds \}\)/);
  // Ticked by default only where the API suggests it (a pinned version, not plain `php`).
  assert.match(review, /new Set\(jobs\.filter\(\(job\) => job\.suggested\)\.map\(\(job\) => job\.id\)\)/);
  assert.match(review, /cronjobIds: jobs\.filter\(\(job\) => ticked\.has\(job\.id\)\)\.map\(\(job\) => job\.id\)/);
  assert.match(review, /confirmDisabled=\{!canManageCron \|\| ticked\.size === 0\}/);
  // A refusal stays in the dialog with the API's words; nothing moved.
  assert.match(review, /setError\(apiMessage\(err, t\("failed"\)\)\);/);
  assert.match(review, /\{job\.new_command\}/);
});

test("the review is offered after a version change only, from both save paths", () => {
  assert.match(panel, /const \{ data \} = await updateApplicationPhp\(php\.application_id, \{ php_version: saved \}\);[\s\S]*?cronReview\.offer\(data, saved\);/);
  assert.match(panel, /if \(values\.php_version && values\.php_version !== before\) cronReview\.offer\(data, values\.php_version\);/);
  assert.equal((panel.match(/\{cronReview\.dialog\}/g) ?? []).length, 2);
  assert.match(read("app/(app)/applications/[application]/php/page.jsx"), /canManageCron=\{can\(permissions, "cronjob", "manage"\)\}/);
});

test("a new server default says which plain-php cron jobs moved with it", () => {
  const summary = strip(read("components/php/version-summary.jsx"));
  assert.match(summary, /const \{ data \} = await setDefaultPhpVersion\(version\.version\);/);
  assert.match(summary, /cronjobsOnDefaultSchema\.parse\(data\?\.cronjobs_on_default_php \?\? \[\]\)/);
  assert.match(summary, /format\.list\(names, \{ type: "conjunction" \}\)/);
});

test("every new string exists in all 8 languages", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    for (const key of ["title", "description", "fromVersion", "fromDefault", "confirm", "notNow", "switched", "failed"]) {
      assert.ok(m.applications.php.cronReview?.[key], `${locale}: cronReview.${key}`);
    }
    for (const key of ["cronOnDefault", "moreCronjobs"]) assert.ok(m.php.versions[key], `${locale}: php.versions.${key}`);
  }
});
