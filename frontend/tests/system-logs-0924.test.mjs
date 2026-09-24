import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { logSourceSchema } from "../lib/schemas/log.js";
import { isRecentlyActive } from "../lib/logs/recent.js";

const PANEL = fs.readFileSync("components/logs/logs-panel.jsx", "utf8");
const LIST = fs.readFileSync("components/logs/log-source-list.jsx", "utf8");
const PAGE = fs.readFileSync("app/(app)/logs/page.jsx", "utf8");
const CONFIRM = fs.readFileSync("components/ui/confirm-dialog.jsx", "utf8");

const base = { key: "journal", label: "System — Journal", group: "system", readable: true };

test("follow and downloadable survive the schema", () => {
  const parsed = logSourceSchema.parse({ ...base, follow: false, downloadable: false });
  assert.equal(parsed.follow, false);
  assert.equal(parsed.downloadable, false);
  // A server too old to send them keeps the old behaviour.
  const old = logSourceSchema.parse(base);
  assert.equal(old.follow, true);
  assert.equal(old.downloadable, true);
});

test("Live on a source without a cursor re-reads and replaces, never appends", () => {
  assert.match(PANEL, /const appends = source\?\.follow !== false/);
  const branch = PANEL.slice(PANEL.indexOf("if (!appends) {"), PANEL.indexOf("{ after: cursor.current }"));
  assert.match(branch, /readLog\(sourceKey, \{ lines: lineCount \}\)/);
  assert.match(branch, /setLines\(data\?\.log\?\.lines \?\? \[\]\)/);
  assert.doesNotMatch(branch, /\.\.\.prev/);
});

test("'the whole file' is only said of a log with a file size", () => {
  assert.match(PANEL, /wholeFile=\{!truncated && lines\.length > 0 && source\?\.size != null\}/);
});

test("Download is hidden where the API refuses it", () => {
  assert.match(PANEL, /showDownload=\{source\?\.downloadable !== false\}/);
});

test("'written just now' reads the stamp as UTC, whatever the browser's zone", () => {
  // The API writes date('d-m-Y H:i:s') in UTC. 09:00 UTC is 14:30 in Kolkata:
  // read as local time it was five and a half hours off.
  const now = Date.UTC(2026, 8, 24, 9, 2, 0);
  assert.equal(isRecentlyActive("24-09-2026 09:00:00", now), true);
  assert.equal(isRecentlyActive("24-09-2026 08:50:00", now), false);
});

test("the dots are drawn with the server render's clock until the first poll", () => {
  assert.match(PAGE, /renderedAt=\{renderedAt\}/);
  assert.match(PANEL, /useState\(renderedAt\)/);
  assert.match(LIST, /isRecentlyActive\(source\.modified, now\)/);
});

test("the line window is remembered like the application Logs page", () => {
  assert.match(PAGE, /parseLinesPref\(cookieStore\.get\(LINES_COOKIE\)\?\.value, DEFAULT_LINES\)/);
  assert.match(PAGE, /getLog\(selected, \{ lines \}\)/);
  assert.match(PANEL, /writeCookie\(LINES_COOKIE, String\(next\)\)/);
  assert.doesNotMatch(PANEL, /onLinesChange=\{setLineCount\}/);
});

test("phone picker: each locked log on its own row, reason written on it", () => {
  assert.match(LIST, /className="flex"\s*>\s*<SelectItem/);
  assert.match(LIST, /t\("locked\.title"\)/);
});

test("a confirmation cannot be closed while its action is running", () => {
  assert.match(CONFIRM, /if \(!next && pending\) return;/);
  assert.match(CONFIRM, /onOpenChange=\{handleOpenChange\}/);
});

test("the page title says System logs in every locale, like the sidebar", () => {
  const titles = Object.fromEntries(
    ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"].map((l) => [l, JSON.parse(fs.readFileSync(`messages/${l}.json`, "utf8")).logs.title]),
  );
  assert.deepEqual(titles, {
    en: "System Logs",
    es: "Registros del sistema",
    hi: "सिस्टम लॉग",
    de: "Systemprotokolle",
    fr: "Journaux système",
    pt: "Logs do sistema",
    ja: "システムログ",
    ru: "Системные журналы",
  });
});
