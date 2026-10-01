import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const source = fs.readFileSync(
  path.join(root, "components/backups/backups-history-table.jsx"),
  "utf8",
);

function functionSource(name, nextName) {
  const start = source.indexOf(`function ${name}`);
  const end = source.indexOf(`function ${nextName}`, start);
  assert.notEqual(start, -1, `${name} must exist`);
  assert.notEqual(end, -1, `${nextName} must follow ${name}`);
  return source.slice(start, end);
}

test("backup failure reasons cannot widen the history table", () => {
  const statusCell = functionSource("StatusCell", "TypeCell");

  // One bound for both screens (1 Oct: 208px on the server-wide list pushed
  // Restore off the card at 1280–1366 in six locales; 0 overflow now in all
  // eight at 1280, 1366 and 1536).
  assert.match(statusCell, /const width = "w-32 max-w-32";/);
  assert.match(statusCell, /cn\(width, "min-w-0 space-y-1"\)/);
  assert.match(statusCell, /line-clamp-2 whitespace-normal break-words/);
  assert.match(statusCell, /<Tooltip>/);
  assert.match(statusCell, /<TooltipContent[^>]*>\s*\{reason\}/);
  assert.match(statusCell, /tabIndex=\{0\}/);
  assert.doesNotMatch(statusCell, /className="truncate text-xs/);
});
