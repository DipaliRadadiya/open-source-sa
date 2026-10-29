import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";
import { createCronjobSchema } from "../lib/schemas/cronjob.js";

const read = (p) => fs.readFileSync(p, "utf8");
const field = read("components/cron-jobs/command-field.jsx");

// oss-api, 29 Oct (found live on .71): picking another site kept the first site's user.
test("Runs as follows the picked site, not only while empty", () => {
  assert.doesNotMatch(field, /!form\.getValues\("run_as"\) && site\.system_user\?\.id/);
  assert.match(field, /if \(site\.system_user\?\.id\) \{\s*form\.setValue\("run_as", String\(site\.system_user\.id\)/);
});

// The form never sent application_id, so the job was not listed under its site, not offered on its
// PHP switch, and not removed with it.
test("the picked site becomes the job's application_id; a typed path or Custom unlinks it", () => {
  assert.match(field, /linkSite\(site\.id\);/);
  assert.equal(field.match(/linkSite\(null\);/g)?.length, 3);
  assert.match(read("components/cron-jobs/create-cronjob-dialog.jsx"), /\.\.\.\(values\.application_id \? \{ application_id: values\.application_id \} : \{\}\)/);
  const edit = read("components/cron-jobs/edit-cronjob-dialog.jsx");
  assert.match(edit, /application_id: job\.application_id \?\? null,/);
  assert.match(edit, /\(values\.application_id \?\? null\) !== before\.application_id \? \{ application_id: values\.application_id \?\? null \} : \{\}/);
  // A duplicate stays on the same site.
  assert.match(read("components/cron-jobs/cronjobs-panel.jsx"), /application_id: job\.application_id \?\? null,/);
});

test("the schema keeps application_id instead of stripping it", () => {
  const base = { name: "Scheduler", run_as: "2", command: "php artisan schedule:run", expression: "* * * * *", active: true };
  assert.equal(createCronjobSchema.parse({ ...base, application_id: 7 }).application_id, 7);
  assert.equal(createCronjobSchema.parse({ ...base, application_id: null }).application_id, null);
  assert.equal(createCronjobSchema.parse(base).application_id, undefined);
});
