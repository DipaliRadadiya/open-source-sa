import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

// Node.js QA, 30 Sep (fresh server).

test("after Update npm the card re-reads instead of patching one number", () => {
  const src = read("components/node/version-summary.jsx");
  assert.match(src, /const npm = version\.npm_version \?\? null;/);
  assert.doesNotMatch(src, /setNpm/);
  assert.match(src, /updateNodeNpm\(version\.version\);\n[\s\S]{0,200}await refreshAndWait\(\);/);
});

test("an empty Node catalog says it could not be fetched, not that nothing exists", () => {
  const en = JSON.parse(read("messages/en.json")).node.install.noneAvailable;
  assert.match(en, /could not be fetched/);
  assert.match(en, /nodejs\.org/);
});

test("red buttons in dark mode clear 4.5:1", () => {
  assert.match(read("components/ui/button.jsx"), /dark:bg-destructive\/20 dark:text-\[color-mix\(in_oklch,var\(--destructive\)_80%,var\(--foreground\)\)\]/);
});

test("installer output wraps, and the selected version chip is scrolled into view", () => {
  assert.match(read("components/runtime/install-output.jsx"), /break-words whitespace-pre-wrap/);
  const bar = read("components/runtime/version-bar.jsx");
  assert.match(bar, /querySelector\('\[aria-current="page"\]'\)/);
  assert.match(bar, /scrollIntoView\(\{ block: "nearest", inline: "nearest" \}\)/);
});
