import test from "node:test";
import assert from "node:assert/strict";

import { preselectOption, preselectVersion } from "../lib/runtime/preselect-version.js";

/**
 * The create form preselects a runtime version. It looks for the entry the
 * server marks as default and falls back to the first in the list — and the
 * API returns versions newest-first, so losing `is_default` in the mapping
 * silently preselected the newest instead. On a server defaulting to Node 24
 * that meant every new site was offered an end-of-life Node 25.
 *
 * This file used to declare its own copy of the rule, which the form had
 * written out three separate times. All four are now the module below.
 */
const NODE = [
  { version: "25.9.0", is_default: false },
  { version: "24.20.0", is_default: true },
];

test("the server's default version is the one preselected", () => {
  assert.equal(preselectVersion(NODE), "24.20.0");
});

test("dropping is_default falls back to the newest — the old behaviour", () => {
  const stripped = NODE.map(({ version }) => ({ version }));
  assert.equal(preselectVersion(stripped), "25.9.0");
});

test("a separately-declared preference wins when the list has it", () => {
  assert.equal(preselectVersion(NODE, "25.9.0"), "25.9.0");
});

test("a preference the server cannot install is ignored, not offered", () => {
  // A stale default must not preselect a version that is not on the box —
  // the form would submit something the API rejects.
  assert.equal(preselectVersion(NODE, "18.0.0"), "24.20.0");
  assert.equal(preselectVersion(NODE, ""), "24.20.0");
});

test("with no default marked, the first entry is used", () => {
  assert.equal(preselectVersion([{ version: "8.4" }]), "8.4");
});

test("an empty or missing list selects nothing rather than throwing", () => {
  assert.equal(preselectVersion([]), undefined);
  assert.equal(preselectVersion(), undefined);
  assert.equal(preselectVersion(null, "8.4"), undefined);
});

test("the option-shaped list follows the same rule", () => {
  const options = NODE.map((v) => ({ value: v.version, label: v.version, is_default: v.is_default }));
  assert.equal(preselectOption(options), "24.20.0");
  assert.equal(preselectOption(options.map(({ value, label }) => ({ value, label }))), "25.9.0");
  assert.equal(preselectOption([]), undefined);
  assert.equal(preselectOption(), undefined);
});
