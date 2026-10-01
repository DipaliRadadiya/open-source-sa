import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const en = JSON.parse(read("messages/en.json")).services;

// Services QA, 30 Sep (fresh server).

test("the summary counts a stopped service as stopped, not running", () => {
  const panel = read("components/services/services-panel.jsx");
  assert.match(panel, /const active = running\.filter\(\(s\) => s\.status !== "inactive"\);/);
  assert.match(panel, /t\("summary\.stopped", \{ count: stopped \}\)/);
  assert.equal(en.sections.running.title, "Installed services");
});

test("table or cards is decided by the space the content has, not the screen", () => {
  const panel = read("components/services/services-panel.jsx");
  assert.match(panel, /@container\/svc/);
  assert.match(panel, /@min-\[900px\]\/svc:hidden/);
  assert.doesNotMatch(panel, /className="hidden lg:block"/);
});

test("the row's main action keeps its own colour everywhere (neutral, not the in-card tint)", () => {
  assert.match(read("components/services/service-actions.jsx"), /variant="neutral"\s*\n\s*size="sm"\s*\n\s*className=\{cn\(ACTION_META\[primary\]\.tone\)\}/);
});

test("config test is a read and is open to view-only, as the API allows", () => {
  assert.match(read("components/services/service-actions.jsx"), /disabled=\{configTest\.pending\}/);
});

test("a failed unit with no log does not promise one; no answer is not 'left as it was'", () => {
  assert.match(read("components/services/service-attention-list.jsx"), /t\("attention\.unitFailedNoLog"\)/);
  for (const f of ["components/services/service-actions.jsx", "components/services/service-boot-switch.jsx"]) {
    assert.match(read(f), /error\.response\s*\n?\s*\? t\(`error\.\$\{action\}`/, f);
  }
  assert.ok(en.error.noAnswer && en.attention.unitFailedNoLog);
});
