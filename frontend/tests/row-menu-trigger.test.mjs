import fs from "node:fs";
import path from "node:path";
import { test } from "node:test";
import assert from "node:assert/strict";

// Every ⋯ row menu is the same plain (ghost) button; Databases had a framed one (Krishna, 20 Nov).
// Exempt: the application page header, whose ⋯ sits in a row of outline buttons (it passes
// triggerVariant="outline"), and the site-facts tile's small menu.
const EXEMPT = new Set(["components/applications/site-facts-card.jsx"]);

function files(dir) {
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((e) =>
    e.isDirectory() ? files(path.join(dir, e.name)) : e.name.endsWith(".jsx") ? [path.join(dir, e.name)] : []);
}

test("every ⋯ menu trigger in a list is a ghost button", () => {
  const root = new URL("..", import.meta.url).pathname;
  const offenders = [];
  for (const file of files(path.join(root, "components"))) {
    const rel = path.relative(root, file);
    if (EXEMPT.has(rel)) continue;
    const src = fs.readFileSync(file, "utf8");
    for (const m of src.matchAll(/<(MoreHorizontal|Ellipsis)\b/g)) {
      const before = src.slice(0, m.index);
      const button = before.slice(before.lastIndexOf("<Button"));
      if (!button.startsWith("<Button")) continue;
      const variant = button.match(/variant=(?:"([^"]+)"|\{(\w+)\})/);
      const value = variant?.[1] ?? variant?.[2];
      if (value !== "ghost" && value !== "triggerVariant") offenders.push(`${rel}: ${value}`);
    }
  }
  assert.deepEqual(offenders, []);
});
