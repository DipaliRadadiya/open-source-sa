import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const banner = read("components/applications/files/archive-jobs-banner.jsx");
const panel = read("components/applications/files/files-panel.jsx");
const api = read("lib/api/files.js");

const LOCALES = ["en", "es", "fr", "de", "hi", "ja", "pt", "ru"];
const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]),
);

/*
 * Compress and extract moved to the queue, so the endpoint now returns 202 and
 * the archive appears minutes later.
 *
 * That makes the button look broken unless something says otherwise: the
 * dialog closes, the listing does not change, and the file turns up later with
 * no explanation. Worse than the timeout it replaced, which at least said
 * something eventually. These tests are about the parts that stop that.
 */

test("the panel polls for archive jobs", () => {
  assert.match(panel, /<ArchiveJobsBanner appId=\{appId\} \/>/);
  assert.match(api, /files\/archive-jobs/);
});

test("a finished job refreshes the server-rendered listing", () => {
  // The listing is server-rendered. A client-side poll that updates its own
  // state and stops there leaves the file list showing page-load contents
  // forever — the archive never appears until the user reloads by hand.
  assert.match(banner, /router\.refresh\(\)/);

  // And only when something actually landed: refreshing on every poll would
  // re-render the whole listing every two seconds.
  assert.match(banner, /if \(landed\) router\.refresh\(\)/);
});

test("a completed job is announced once, not on every poll", () => {
  // Completed rows stay in the response for five minutes so a job cannot
  // vanish between two polls. Without a seen-set that becomes one toast every
  // two seconds for five minutes.
  assert.match(banner, /announced/);
  assert.match(banner, /announced\.current\.has\(job\.id\)/);
  assert.match(banner, /announced\.current\.add\(job\.id\)/);
});

test("polling stops when the component goes away", () => {
  // An interval that outlives the page keeps hitting the API from a screen
  // nobody is looking at.
  assert.match(banner, /clearInterval\(id\)/);
  assert.match(banner, /controller\.abort\(\)/);
});

test("a failed job shows the server's own sentence", () => {
  // `reason` is stored as a code and the sentence is built server-side in the
  // viewer's locale. Substituting a generic client string here would throw
  // away the only specific thing the user is told.
  assert.match(banner, /job\.message \?\? t\("failed"\)/);
});

test("the success wording no longer claims the work is done", () => {
  // The old strings were "compressed." and "extracted." — past tense, for work
  // that at this point has not started.
  for (const locale of LOCALES) {
    const files = messages[locale].applications.files;

    for (const key of ["compressDialog", "extractDialog"]) {
      assert.ok(files[key].done, `${locale}.${key}.done missing`);
    }

    assert.ok(files.archiveJobs, `${locale}.archiveJobs missing`);

    for (const op of ["compress", "extract"]) {
      assert.ok(files.archiveJobs.running[op], `${locale}.archiveJobs.running.${op} missing`);
      assert.ok(files.archiveJobs.done[op], `${locale}.archiveJobs.done.${op} missing`);
    }

    assert.ok(files.archiveJobs.failed, `${locale}.archiveJobs.failed missing`);
  }
});

test("the running message keeps its target placeholder in every locale", () => {
  // A message that drops {target} names no file, which on a screen that can
  // have two archives building at once is the whole content of the line.
  for (const locale of LOCALES) {
    const running = messages[locale].applications.files.archiveJobs.running;

    for (const op of ["compress", "extract"]) {
      assert.match(running[op], /\{target\}/, `${locale}.running.${op} lost {target}`);
    }
  }
});
