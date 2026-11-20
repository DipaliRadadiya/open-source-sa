import fs from "node:fs";
import { test } from "node:test";
import assert from "node:assert/strict";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

// Krishna, 20 Nov: the same control looked different on different pages.
test("a backup row's download icon is plain like other row buttons; the labelled card one stays framed", () => {
  assert.match(read("components/backups/download-backup-button.jsx"), /variant=\{label \? "outline" : "ghost"\}/);
});

test("Install version is filled, like every page's main add button", () => {
  const src = read("components/runtime/install-version-button.jsx");
  const trigger = src.slice(src.indexOf("<ReasonTooltip"), src.indexOf("</ReasonTooltip>"));
  assert.doesNotMatch(trigger, /variant=/);
});

test("the error log search has the same width as every list's", () => {
  const src = read("components/admin/error-logs/error-log-panel.jsx");
  assert.match(src, /<LocalSearchInput value=\{search\} onChange=\{setSearch\} placeholder=\{t\("searchPlaceholder"\)\} \/>/);
  assert.doesNotMatch(src, /min-w-48 flex-1/);
});

test("the log viewer reloads with the same icon as every Refresh button", () => {
  const src = read("components/logs/log-toolbar.jsx");
  assert.match(src, /icon=\{RefreshCw\}\s+label=\{t\("reload"\)\}/);
  assert.doesNotMatch(src, /RotateCw/);
});
