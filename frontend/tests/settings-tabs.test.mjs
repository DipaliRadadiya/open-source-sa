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

test("the tab bar is sized to its tabs, like Backups", () => {
  // 8 Oct (Krishna: Settings looked unlike every other page): a grid stretched
  // across the page, now a strip as wide as its tabs, so there is no column
  // count to keep in step with the tab list.
  assert.match(tabs, /className="inline-flex w-fit gap-1 rounded-lg bg-muted p-1"/);
  assert.doesNotMatch(tabs, /grid-cols-\d/);
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
