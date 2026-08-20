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

test("the state that made this a bug is still held in the Node card", () => {
  /*
   * If npm stops being local state — derived from props instead — the key is
   * no longer load-bearing for that field and this suite is over-claiming.
   * Better to be told than to keep asserting something that has quietly become
   * decorative.
   */
  const source = fs.readFileSync(path.join(root, "components/node/version-summary.jsx"), "utf8");
  assert.match(
    source,
    /useState\(version\.npm_version/,
    "node version-summary no longer seeds npm from props — re-check whether the key on the page is still needed",
  );
});
