import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

import { formatDockerSize, localizeSizeText } from "../lib/format/bytes.js";

const root = path.resolve(import.meta.dirname, "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");
const format = (locale) => ({
  number: (value, options) => new Intl.NumberFormat(locale, options).format(value),
});

test("an API size keeps its digits and takes the reader's separators", () => {
  assert.equal(localizeSizeText("1.6 GB", format("en")), "1.6 GB");
  assert.equal(localizeSizeText("1.6 GB", format("de")), "1,6 GB");
  assert.equal(localizeSizeText("1,000 MB", format("de")), "1.000 MB");
  assert.equal(localizeSizeText("408 MB", format("fr")), "408 MB");
  assert.equal(localizeSizeText("0 B", format("ru")), "0 B");
});

test("anything that is not a plain size comes back unchanged", () => {
  assert.equal(localizeSizeText("—", format("de")), "—");
  assert.equal(localizeSizeText("not measured", format("de")), "not measured");
  assert.equal(localizeSizeText(null, format("de")), null);
});

test("Docker's own size text reads like a file size", () => {
  // DOCKER-QA D1: a volume showed "1.618MB" in every language.
  assert.equal(formatDockerSize("1.618MB", format("en")), "1.6 MB");
  assert.equal(formatDockerSize("1.618MB", format("de")), "1,6 MB");
  assert.equal(formatDockerSize("0B", format("en")), "0 B");
  assert.equal(formatDockerSize("12.3kB", format("fr")), "12,3 kB");
  assert.equal(formatDockerSize("", format("en")), null);
});

test("the size screens localize what the API rendered", () => {
  for (const file of [
    "components/docker/docker-resources-panel.jsx",
    "components/disk-cleaner/cleanup-panel.jsx",
    "components/disk-cleaner/disk-summary.jsx",
    "components/databases/databases-table.jsx",
    "components/services/services-table.jsx",
  ]) {
    assert.match(read(file), /formatDockerSize|localizeSizeText|SizeText/, file);
  }
});
