import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

import { formatBytes, formatFileSize } from "../lib/format/bytes.js";

const root = path.resolve(import.meta.dirname, "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

// next-intl's format.number is Intl.NumberFormat underneath.
const format = (locale) => ({
  number: (value, options) => new Intl.NumberFormat(locale, options).format(value),
});
const en = format("en");

test("a file reads the same as in Ubuntu's file manager", () => {
  // Bug #17: Ubuntu showed 243.6 MB, the upload dialog 232 MB, for one .tar.
  assert.equal(formatFileSize(243_616_768, en), "243.6 MB");
  assert.equal(formatBytes(243_616_768, en), "232 MB");
  assert.equal(formatFileSize(3_900, en), "3.9 kB");
  assert.equal(formatFileSize(26_100_000, en), "26.1 MB");
  assert.equal(formatFileSize(1_000_000, en), "1.0 MB");
  assert.equal(formatFileSize(2_500_000_000, en), "2.5 GB");
});

test("unit boundaries step on the rounded figure", () => {
  assert.equal(formatFileSize(0, en), "0 B");
  assert.equal(formatFileSize(999, en), "999 B");
  assert.equal(formatFileSize(999.6, en), "1.0 kB");
  assert.equal(formatFileSize(1_000, en), "1.0 kB");
  assert.equal(formatFileSize(1_024, en), "1.0 kB");
  assert.equal(formatFileSize(999_949, en), "999.9 kB");
  assert.equal(formatFileSize(999_960, en), "1.0 MB");
  assert.equal(formatFileSize(5e15, en), "5,000.0 TB");
});

test("not a byte count renders nothing, never 0 B or NaN", () => {
  for (const value of [null, undefined, "", "abc", -1, Number.NaN, Infinity]) {
    assert.equal(formatFileSize(value, en), null, String(value));
  }
  assert.equal(formatFileSize("2048", en), "2.0 kB");
});

test("the number follows the locale", () => {
  assert.equal(formatFileSize(243_616_768, format("de")), "243,6 MB");
  assert.equal(formatFileSize(1_500, format("fr")), "1,5 kB");
});

test("every file-manager size is built from bytes, not the backend's 1024 label", () => {
  const files = [
    "components/applications/files/files-table.jsx",
    "components/applications/files/files-cards.jsx",
    "components/applications/files/trash-panel.jsx",
    "components/applications/files/size-breakdown-sheet.jsx",
    "components/applications/files/upload-dialog.jsx",
    "components/applications/files/archive-jobs-banner.jsx",
    "lib/applications/get-trash.js",
  ];
  for (const file of files) {
    const source = read(file);
    assert.doesNotMatch(source, /\b(size|bytes|total_size|total_bytes)_human\b/, file);
    assert.doesNotMatch(source, /formatBytes\b/, file);
  }
});

test("hovering a size shows the exact bytes, translated", () => {
  const source = read("components/applications/files/file-size.jsx");
  assert.match(source, /title=\{bytes >= 1000 \? t\("exactBytes", \{ bytes \}\)/);
  for (const locale of ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"]) {
    const messages = JSON.parse(read(`messages/${locale}.json`));
    assert.match(messages.applications.files.exactBytes, /^\{bytes, plural, /, locale);
  }
});
