import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { centralStatusResponseSchema } from "../lib/schemas/central.js";
import { aiBotPoliciesResponseSchema } from "../lib/schemas/application.js";

// Bugs #49 (Central "Waiting for Central") and #86 (robots.txt lines), 2026-10-05.
const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

test("#49: the status keeps `connected` and `last_used_at` (payload from the test server)", () => {
  const waiting = centralStatusResponseSchema.parse({
    central: { enabled: true, token: "sv_central_8***", connected: false, last_used_at: null },
  });
  assert.equal(waiting.central.connected, false);
  const linked = centralStatusResponseSchema.parse({
    central: { enabled: true, token: "sv_central_g***", connected: true, last_used_at: "2026-10-05T06:18:15+00:00" },
  });
  assert.equal(linked.central.connected, true);
  assert.equal(linked.central.last_used_at, "2026-10-05T06:18:15+00:00");
  // An older backend without the field reads as not yet connected, never as connected.
  assert.equal(centralStatusResponseSchema.parse({ central: { enabled: true, token: "x" } }).central.connected, false);
});

test("#49: a key alone is 'waiting', not 'connected'", () => {
  const panel = read("components/admin/central/central-panel.jsx");
  assert.match(panel, /const linked = connected && Boolean\(status\?\.connected\)/);
  assert.match(panel, /state\.waiting/);
  assert.doesNotMatch(panel, /keyActive/);
});

test("#86: robots.txt lines and the per-policy flag survive the schema", () => {
  const parsed = aiBotPoliciesResponseSchema.parse({
    ai_bot_policies: {
      allow_all: { title: "Allow", description: "", blocked_bots: [], blocked_count: 0, robots_txt_recommended: false },
      block_training: { title: "Block", description: "", blocked_bots: ["GPTBot"], blocked_count: 1, robots_txt_recommended: true },
    },
    robots_txt: { note: "Add these lines:", lines: "User-agent: Google-Extended\nDisallow: /\n" },
  });
  assert.equal(parsed.ai_bot_policies.block_training.robots_txt_recommended, true);
  assert.equal(parsed.ai_bot_policies.allow_all.robots_txt_recommended, false);
  assert.match(parsed.robots_txt.lines, /Google-Extended/);
  // Without the block (older backend) the page still loads.
  assert.equal(aiBotPoliciesResponseSchema.parse({ ai_bot_policies: {} }).robots_txt, undefined);
});

test("#86: the box follows the chosen option's flag", () => {
  const section = read("components/applications/bot-blocker/bot-blocker-section.jsx");
  assert.match(section, /selected\?\.robots_txt_recommended && robotsTxt\?\.lines/);
});
