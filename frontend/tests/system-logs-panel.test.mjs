import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { failedRead } from "../lib/logs/failed-read.js";

const PANEL = fs.readFileSync("components/logs/logs-panel.jsx", "utf8");
const APP_PANEL = fs.readFileSync("components/applications/logs/application-logs-panel.jsx", "utf8");
const VIEWER = fs.readFileSync("components/logs/log-viewer.jsx", "utf8");

const res = (body, type = "application/json") => new Response(typeof body === "string" ? body : JSON.stringify(body), { status: 500, headers: { "content-type": type } });

test("a refused read keeps the server's reason and its reference", async () => {
  const out = await failedRead(res({ message: "Reading the log failed on the server.", reference: "ref-1" }));
  assert.equal(out.status, "failed");
  assert.equal(out.message, "Reading the log failed on the server. · ref-1");
});

test("no usable reason leaves the generic sentence to the viewer", async () => {
  assert.equal((await failedRead(res("<html>502</html>", "text/html"))).message, null);
  // A lookup key is not a sentence.
  assert.equal((await failedRead(res({ message: "errors/logs.read_failed" }))).message, null);
});

test("the viewer shows that reason instead of 'the server did not answer'", () => {
  assert.match(VIEWER, /body=\{failedMessage \?\? t\("readFailed\.body"\)\}/);
});

test("switching logs happens in place, not by a server navigation", () => {
  // router.replace re-rendered the page on the server and read the log twice.
  assert.doesNotMatch(PANEL, /router\.replace/);
  assert.match(PANEL, /window\.history\.replaceState/);
  assert.match(PANEL, /setStatus\("loading"\)/);
  assert.match(PANEL, /loadingText=\{t\("loadingSource"/);
});

test("the catalog poll does not re-read the log", () => {
  // New source objects every 30s rebuilt `load`, and the effect re-ran it.
  assert.match(PANEL, /\[sourceKey, readable, lineCount, debouncedTerm, t\]/);
  // Primitives only — `appends` is a boolean read off the source, not the object.
  assert.match(PANEL, /\[follow, disabled, debouncedTerm, sourceKey, appends, lineCount\]/);
});

test("a first read that fails is one box, not a box and a toast", () => {
  for (const src of [PANEL, APP_PANEL]) {
    assert.match(src, /statusRef\.current === "loading"/);
    assert.match(src, /setFailedMessage\(apiMessage\(error, null\) \|\| null\)/);
  }
});

test("Reload stays usable when a read failed, on both log screens", () => {
  const TOOLBAR = fs.readFileSync("components/logs/log-toolbar.jsx", "utf8");
  assert.match(TOOLBAR, /disabled=\{disabled && !reloadable\}/);
  for (const src of [PANEL, APP_PANEL]) assert.match(src, /reloadable=\{status === "failed"\}/);
});
