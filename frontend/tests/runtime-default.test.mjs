import test from "node:test";
import assert from "node:assert/strict";

/**
 * The create form preselects a runtime version. It looks for the entry the
 * server marks as default and falls back to the first in the list — and the
 * API returns versions newest-first, so losing `is_default` in the mapping
 * silently preselected the newest instead. On a server defaulting to Node 24
 * that meant every new site was offered an end-of-life Node 25.
 */
const NODE = [
  { version: "25.9.0", is_default: false },
  { version: "24.20.0", is_default: true },
];

const optionsFor = (versions) =>
  versions.map((v) => ({ value: v.version, label: v.version, is_default: v.is_default }));

const preselected = (options) =>
  options.find((o) => o.is_default)?.value ?? options[0]?.value;

test("the server's default version is the one preselected", () => {
  assert.equal(preselected(optionsFor(NODE)), "24.20.0");
});

test("dropping is_default falls back to the newest — the old behaviour", () => {
  const stripped = NODE.map((v) => ({ value: v.version, label: v.version }));
  assert.equal(preselected(stripped), "25.9.0");
});

test("with no default marked, the first entry is still used", () => {
  assert.equal(preselected(optionsFor([{ version: "8.4" }])), "8.4");
  assert.equal(preselected([]), undefined);
});
