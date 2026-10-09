import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { cronjobsResponseSchema } from "../lib/schemas/cronjob.js";

const read = (p) => fs.readFileSync(p, "utf8");

// Backend 9733ab81 (7 Oct): meta.usernames lists every run-as account across pages, and
// meta.cron_running says when the cron daemon is down.
test("the list keeps meta.usernames and meta.cron_running", () => {
  const parsed = cronjobsResponseSchema.parse({
    cronjobs: [],
    meta: { current_page: 1, per_page: 10, total: 0, last_page: 1, usernames: ["root", "www-data"], cron_running: false },
  });
  assert.deepEqual(parsed.meta.usernames, ["root", "www-data"]);
  assert.equal(parsed.meta.cron_running, false);
  // An older API without them still parses.
  assert.ok(cronjobsResponseSchema.parse({ cronjobs: [], meta: { current_page: 1, per_page: 10, total: 0, last_page: 1 } }));
});

test("the Runs as filter offers every account, not just this page's", () => {
  const bar = read("components/cron-jobs/cronjobs-toolbar.jsx");
  assert.match(bar, /usernames\s*\?\s*usernames\.filter\(\(name\) => !managed\.has\(name\)\)/);
  assert.match(read("components/cron-jobs/cronjobs-panel.jsx"), /usernames=\{meta\?\.usernames \?\? null\}/);
});

test("the red notice shows only when cron is definitely down", () => {
  const panel = read("components/cron-jobs/cronjobs-panel.jsx");
  assert.match(panel, /meta\?\.cron_running === false \? \(/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const m = JSON.parse(read(`messages/${l}.json`)).cronJobs.cronDown;
    assert.ok(m?.title && m?.body, l);
  }
});
