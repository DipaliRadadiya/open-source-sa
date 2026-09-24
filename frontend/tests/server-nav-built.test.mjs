import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

import { isServerNavBuilt } from "../lib/navigation.js";

const root = path.join(import.meta.dirname, "..");

/*
 * The sidebar holds an allowlist of server screens the frontend has built.
 * Anything the backend advertises but this list omits renders as a
 * non-clickable "Soon" row — which is right for a screen that genuinely does
 * not exist, and silently wrong for one that does.
 *
 * That happened: /docker shipped with a page, a route, a permission and an
 * API, and the sidebar showed "Soon" because nobody added one line to a set in
 * another file. The page was unreachable and the only symptom was a badge.
 *
 * So the list is checked against the filesystem, in both directions.
 */

/** Server-level permission URLs, read from the backend's own catalog. */
function catalogUrls() {
  const catalog = path.join(root, "..", "backend", "app", "Services", "PermissionCatalog.php");
  if (!fs.existsSync(catalog)) return null; // frontend-only checkout

  const source = fs.readFileSync(catalog, "utf8");

  return [...source.matchAll(/'url' => '(\/[^']*)'/g)]
    .map((m) => m[1])
    .filter((url) => url !== "");
}

/** Does a Next route exist for this URL? */
function pageExists(url) {
  const base = path.join(root, "app", "(app)", ...url.replace(/^\//, "").split("/"));

  return ["page.jsx", "page.js", "page.tsx"].some((file) => fs.existsSync(path.join(base, file)));
}

test("every server screen with a page is linked, not held back as Soon", () => {
  const urls = catalogUrls();
  if (urls === null) return;

  const unreachable = urls.filter((url) => pageExists(url) && !isServerNavBuilt(url));

  assert.deepEqual(
    unreachable,
    [],
    `These screens have a page but the sidebar shows them as "Soon", so nobody can ` +
      `reach them: ${unreachable.join(", ")}. Add them to BUILT_SERVER_URLS in lib/navigation.js.`,
  );
});

test("nothing is linked that has no page to land on", () => {
  // The other direction, and the reason the list exists at all: linking a
  // screen the frontend has not built sends the user to a 404.
  const urls = catalogUrls();
  if (urls === null) return;

  const broken = urls.filter((url) => isServerNavBuilt(url) && !pageExists(url));

  assert.deepEqual(
    broken,
    [],
    `These are linked in the sidebar but have no page: ${broken.join(", ")}.`,
  );
});

test("the check can actually find pages, so it is not passing on an empty set", () => {
  // Without this, a wrong path root would make both tests above pass over
  // everything and prove nothing.
  const urls = catalogUrls();
  if (urls === null) return;

  assert.ok(urls.length > 5, "expected the catalog to declare server screens");
  assert.ok(pageExists("/dashboard"), "expected to find the dashboard page");
  assert.ok(isServerNavBuilt("/dashboard"));
});
