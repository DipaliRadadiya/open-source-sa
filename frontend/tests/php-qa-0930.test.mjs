import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

// PHP QA, 30 Sep (fresh server).

test("view-only opens php.ini read-only: GET …/ini only needs view", () => {
  const src = read("components/php/ini-editor.jsx");
  assert.match(src, /const readOnly = !canManage;/);
  assert.match(src, /readOnly=\{readOnly\}/);
  assert.match(src, /disabled=\{loading \|\| Boolean\(unavailableReason\)\}/);
  assert.match(src, /\{readOnly \? null : \(\s*\n\s*<ReasonTooltip reason=\{blockedReason\}>/);
  const en = JSON.parse(read("messages/en.json")).services.phpIni;
  assert.equal(en.viewAction, "View php.ini");
});

test("extension descriptions wrap instead of being cut on a phone", () => {
  const src = read("components/php/extensions-card.jsx");
  assert.match(src, /<span className="block text-xs whitespace-normal text-muted-foreground">/);
  assert.doesNotMatch(src, /block truncate text-xs text-muted-foreground/);
});

test("version chips are newest first whatever order the API sends", () => {
  const src = read("components/runtime/version-bar.jsx");
  assert.match(src, /\[\.\.\.versions\]\.sort\(\(a, b\) => compareVersions\(b\.version, a\.version\)\)/);
  assert.match(src, /\{ordered\.map\(/);
});

test("the Extensions tab counts what the card counts (built-ins listed apart)", () => {
  assert.match(read("app/(app)/php/page.jsx"), /extensionCount=\{extensions\?\.extensions\?\.filter\(\(e\) => !e\.builtin\)\.length\}/);
});

test("a change that got no answer never claims it failed", async () => {
  const { apiMessage } = await import("../lib/api/error-message.js");
  const { noAnswerMessage } = await import("../lib/api/generic-error.js");
  const dropped = (method) => ({ isAxiosError: true, code: "ERR_NETWORK", config: { method } });
  assert.equal(apiMessage(dropped("put"), "That version could not be made the default."), noAnswerMessage());
  assert.equal(apiMessage(dropped("delete"), "x"), noAnswerMessage());
  // A read that got nothing really did not load.
  assert.equal(apiMessage(dropped("get"), "The php.ini could not be read."), "The php.ini could not be read.");
  // Cancelled on purpose is not a dropped line.
  assert.equal(apiMessage({ ...dropped("post"), code: "ERR_CANCELED" }, "x"), "x");
  // An answer, even an error, is still the server's word.
  const answered = { isAxiosError: true, config: { method: "put" }, response: { status: 500, data: { message: "Server Error", reference: "R1" } } };
  assert.equal(apiMessage(answered, "That failed."), "That failed. · R1");
});
