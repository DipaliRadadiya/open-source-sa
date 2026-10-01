import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

/*
 * The Node and PHP pages both render a <VersionSummary> that holds local state
 * seeded from its props — the npm version on Node, the loaded php.ini contents
 * on PHP. A `useState` initialiser runs only on mount, and React reuses the
 * instance when only the props change, so switching version left the previous
 * version's data on screen. On Node that showed as the wrong npm number; on PHP
 * it is an editor buffer belonging to a version you are no longer looking at.
 *
 * `key` is the whole fix, and it is one token that a later edit could drop
 * without anything failing — the page would still build, still render, and go
 * back to lying only when someone with two versions installed clicks between
 * them. Neither test box has two versions, so this is the only check that runs.
 */
const PAGES = [
  { label: "node", file: "app/(app)/node/page.jsx" },
  { label: "php", file: "app/(app)/php/page.jsx" },
];

for (const { label, file } of PAGES) {
  test(`${label} page keys VersionSummary on the version`, () => {
    const source = fs.readFileSync(path.join(root, file), "utf8");

    const open = source.indexOf("<VersionSummary");
    assert.notEqual(open, -1, `${file} no longer renders VersionSummary`);

    // Just the opening tag: attributes end at the first '>' that closes it.
    const tag = source.slice(open, source.indexOf(">", open));

    assert.match(
      tag,
      /key=\{current\.version\}/,
      `${file} renders VersionSummary without key={current.version}, so its ` +
        `local state will survive a version switch and show the previous ` +
        `version's data`,
    );
  });
}

test("the Node card still holds per-version state, so the key still matters", () => {
  /*
   * npm used to be local state seeded from props; it is derived now (30 Sep:
   * the seeded copy showed "npm 12.1.0 → 12.1.0" after an update). The key is
   * still load-bearing for what IS local — an open Remove confirm or a running
   * action must not follow you to another version.
   */
  const source = fs.readFileSync(path.join(root, "components/node/version-summary.jsx"), "utf8");
  assert.match(source, /const npm = version\.npm_version \?\? null;/);
  assert.match(source, /const \[confirming, setConfirming\] = useState\(false\);/);
  assert.match(source, /const \[running, setRunning\] = useState\(null\);/);
});
