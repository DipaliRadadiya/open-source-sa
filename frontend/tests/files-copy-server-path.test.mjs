import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

import { filesRoot, serverPath } from "../lib/files/server-path.js";

const root = path.resolve(import.meta.dirname, "..");
const read = (file) => fs.readFileSync(path.join(root, file), "utf8");

// Bug #8 (Vishu): Copy path on index.php gave "index.php", not where it is on the server.
test("the File Manager's folder is document_root without web_root", () => {
  assert.equal(filesRoot({ document_root: "/home/qa/qa-php/public_html", web_root: null }), "/home/qa/qa-php/public_html");
  assert.equal(filesRoot({ document_root: "/home/qa/qa-php/public_html", web_root: "" }), "/home/qa/qa-php/public_html");
  assert.equal(filesRoot({ document_root: "/home/u/laravel/public_html/public", web_root: "public" }), "/home/u/laravel/public_html");
  assert.equal(filesRoot({ document_root: "/home/u/craft/public_html/web", web_root: "/web/" }), "/home/u/craft/public_html");
  assert.equal(filesRoot({ document_root: "/home/u/a/public_html/x/y", web_root: "x/y" }), "/home/u/a/public_html");
});

test("an unknown folder copies the relative path rather than a wrong one", () => {
  assert.equal(filesRoot({ document_root: null, web_root: null }), null);
  assert.equal(filesRoot(null), null);
  // web_root that document_root does not end with: cannot be told apart.
  assert.equal(filesRoot({ document_root: "/home/u/a/public_html/public", web_root: "web" }), null);
  assert.equal(serverPath(null, "wp-admin/index.php"), "wp-admin/index.php");
});

test("a server path joins the folder and the relative path", () => {
  const base = "/home/qa/qa-wp/public_html";
  assert.equal(serverPath(base, "index.php"), `${base}/index.php`);
  assert.equal(serverPath(base, "wp-admin/css"), `${base}/wp-admin/css`);
  assert.equal(serverPath(base, ""), base);
});

test("every Copy path in the File Manager copies the server path", () => {
  for (const file of [
    "components/applications/files/file-row-actions.jsx",
    "components/applications/files/file-actions-menu.jsx",
  ]) {
    const source = read(file);
    assert.match(source, /writeText\(toServerPath\(file\.path\)\)/, file);
    assert.doesNotMatch(source, /writeText\(file\.path\)/, file);
  }
  const crumb = read("components/applications/files/file-breadcrumb.jsx");
  assert.match(crumb, /\{path \|\| root \? <CopyButton value=\{toServerPath\(path\)\}/);
  const page = read("app/(app)/applications/[application]/files/page.jsx");
  assert.match(page, /<FilesRootProvider value=\{filesRoot\(application\)\}>\s*<FilesPanel/);
});
