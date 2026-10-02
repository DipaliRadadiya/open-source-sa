import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("a view-only user sees Clear disabled with the reason, not hidden", () => {
  const panel = read("components/logs/logs-panel.jsx");
  assert.match(panel, /onClear=\{source\?\.clearable \? \(\) => setConfirmClear\(true\) : null\}/);
  assert.match(panel, /clearReason=\{canManage \? null : t\("noPermission"\)\}/);
  const bar = read("components/logs/log-toolbar.jsx");
  assert.match(bar, /disabled=\{disabled \|\| clearing \|\| Boolean\(clearReason\)\}/);
  assert.match(bar, /disabledReason=\{clearReason\}/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) assert.ok(JSON.parse(read(`messages/${l}.json`)).logs.noPermission, l);
});

test("Clear log is red: the destructive variant, not outline overridden by hand", () => {
  const bar = read("components/logs/log-toolbar.jsx");
  const clear = bar.slice(bar.indexOf('<div className="ml-auto">'), bar.indexOf('{t("clear")}'));
  assert.match(clear, /variant="destructive"/);
  assert.doesNotMatch(clear, /variant="outline"/);
});

test("axe fixes on System Logs: gutter, selected source, disabled download name", () => {
  assert.doesNotMatch(read("components/logs/log-line.jsx"), /text-console-muted\/60/);
  assert.match(read("components/logs/log-source-list.jsx"), /bg-primary\/10 font-medium text-\[color-mix\(in_oklch,var\(--primary\)_80%,var\(--foreground\)\)\]/);
  assert.match(read("components/logs/log-toolbar.jsx"), /asChild=\{!disabled\} aria-label=\{disabled \? label : undefined\}/);
});

test("the log header wraps the tail pill instead of squeezing the heading", () => {
  assert.match(read("components/logs/log-toolbar.jsx"), /<div className="flex flex-wrap items-center gap-x-3 gap-y-2">\s*<div className="min-w-48 flex-1">/);
});
