import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { cloneCarries, cloneDrops, cloneFailureTitle, defaultCloneName, cloneFormSchema } from "../lib/schemas/clone.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const panel = read("components/applications/clone/clone-panel.jsx");
const progress = read("components/applications/clone/clone-progress.jsx");

test("CL-I: a confirm click in the first moments after opening is ignored (double-click)", () => {
  const dialog = read("components/ui/confirm-dialog.jsx");
  assert.match(dialog, /if \(Date\.now\(\) - openedAt\.current < 400\) return;/);
});

test("CL-G: the name shown is the one the backend will give", () => {
  assert.equal(defaultCloneName("my-blog", ["my-blog"]), "my-blog (Clone)");
  assert.equal(defaultCloneName("my-blog", ["my-blog (Clone)", "my-blog (Clone) 2"]), "my-blog (Clone) 3");
});

test("CL-F: WordPress copies are not promised a repository or told to reconnect deploys", () => {
  assert.deepEqual(cloneCarries({ needs_database: true }, { repository: null }), ["files", "database", "phpVersion", "webRoot"]);
  assert.deepEqual(cloneCarries({ needs_database: false }, { repository: "a/b" }), ["files", "phpVersion", "webRoot", "buildCommand", "repository"]);
  assert.ok(!cloneDrops({ repository: null }).includes("deploys"));
  assert.ok(cloneDrops({ repository: "a/b" }).includes("deploys"));
  assert.match(progress, /sourceHasRepository && !webhook\?\.url/);
});

test("CL-M: raw keys and raw SQL are never shown as the failure reason", () => {
  assert.equal(cloneFailureTitle({ reason_title: "clone.cloning_errors." }), null);
  assert.equal(cloneFailureTitle({ reason_title: "SQLSTATE[23000]: … (Connection: sqlite, Database: /var/www/panel/backend/database/database.sqlite" }), null);
  assert.equal(cloneFailureTitle({ reason_title: "The database could not be copied." }), "The database could not be copied.");
});

test("CL-B/CL-J: domain problems show while typing, lengths say the limit", () => {
  assert.match(panel, /mode: "onTouched",/);
  const r = cloneFormSchema.safeParse({ domain: "a".repeat(250) + ".nip.io", name: "x".repeat(256) });
  const codes = r.error.issues.map((i) => i.message);
  assert.ok(codes.includes("max255"), codes.join(","));
});

test("CL-D: a clone that never starts can be stopped watching", () => {
  assert.match(progress, /const NOT_STARTING_MS = 2 \* 60 \* 1000;/);
  assert.match(progress, /\{t\("stopWatching"\)\}/);
});

test("CL-E: the next-steps count is counted", () => {
  assert.match(progress, /t\("subtitle", \{ count: steps\.length/);
});

test("CL-H: the domain being confirmed wraps instead of being cut", () => {
  assert.match(panel, /<dd className="min-w-0 font-mono font-medium break-all">/);
});

test("CL-K/CL-L: the suggestion chip is for managers and keeps focus", () => {
  assert.match(panel, /\{canManage && suggestion && !field\.value \? \(/);
  assert.match(panel, /form\.setFocus\("domain"\);/);
});

test("CL-N: Try again keeps the domain it failed on", () => {
  assert.match(panel, /form\.reset\(\{ name: failed\?\.name \?\? "", domain: failed\?\.domain \?\? "" \}\);/);
});

test("CL-A/CL-C: the new sentences exist in every locale", () => {
  for (const l of LOCALES) {
    const c = JSON.parse(read(`messages/${l}.json`)).applications.clone;
    assert.ok(c.blocked.typeNotSupported.body.includes("{type}"), l);
    assert.ok(c.progress.stopWatching && c.progress.notStarting && c.progress.notStartingBody, l);
    assert.match(c.result.next.subtitle, /\{count, plural/, l);
    assert.doesNotMatch(c.warnings.dns, /plain HTTP|einfaches HTTP|HTTP sin cifrar|HTTP brut|HTTP puro|सादे HTTP|plain HTTP|обычному HTTP/, l);
  }
});
