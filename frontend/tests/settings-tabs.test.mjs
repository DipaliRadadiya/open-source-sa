import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");

const tabs = fs.readFileSync(
  path.join(root, "components/settings/settings-tabs.jsx"),
  "utf8",
);

function sectionKeys() {
  return [...tabs.matchAll(/\{ key: "([a-z]+)", href: "([^"]+)"/g)].map(
    ([, key, href]) => ({ key, href }),
  );
}

test("every settings tab points at a route that exists", () => {
  for (const { key, href } of sectionKeys()) {
    const segment = href.replace("/settings/", "");
    const page = path.join(root, "app/(app)/settings", segment);

    assert.ok(
      fs.existsSync(path.join(page, "page.jsx")) ||
        fs.existsSync(path.join(page, "page.js")),
      `tab "${key}" links to ${href} but no page exists there`,
    );
  }
});

test("the tab grid has exactly as many columns as there are tabs", () => {
  // The one thing that breaks silently when a tab is added: the grid keeps its
  // old column count, and the new tab either wraps onto a second row or the
  // last one is squeezed. Nothing errors, and it only shows on a screen
  // narrow enough to notice.
  const count = sectionKeys().length;
  const grid = tabs.match(/grid w-full grid-cols-(\d+)/);

  assert.ok(grid, "the tab bar should declare its column count");
  assert.equal(Number(grid[1]), count);
});

test("each tab has a label in every locale", () => {
  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(
      fs.readFileSync(path.join(root, `messages/${locale}.json`), "utf8"),
    );

    for (const { key } of sectionKeys()) {
      assert.ok(
        messages.settings?.tabs?.[key],
        `missing settings.tabs.${key} in ${locale}`,
      );
    }
  }
});
