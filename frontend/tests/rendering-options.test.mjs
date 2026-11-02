import assert from "node:assert/strict";
import test from "node:test";
import { withServableRenderingTypes } from "../lib/applications/rendering-options.js";

// Tester sheet (2 Nov): a Node server offered "PHP application" as the rendering type, which the
// API then refused ("This server does not serve PHP applications").
const git = () => ({
  name: "git",
  fields: [
    { name: "repository", type: "select" },
    { name: "php_version", type: "select", source: "php_versions" },
    {
      name: "rendering_type",
      type: "select",
      options: [
        { value: "php", label: "PHP application" },
        { value: "ssr", label: "Server-side rendering" },
        { value: "csr", label: "Client-side rendering" },
        { value: "static", label: "Static site" },
      ],
    },
  ],
});
const values = (types) => types[0].fields.find((field) => field.name === "rendering_type").options.map((option) => option.value);

test("a Node server (mern) is not offered PHP", () => {
  assert.deepEqual(values(withServableRenderingTypes([git()], ["node", "static"])), ["ssr", "csr", "static"]);
});

test("a PHP stack keeps all four: it serves php, node and static", () => {
  assert.deepEqual(values(withServableRenderingTypes([git()], ["php", "node", "static"])), ["php", "ssr", "csr", "static"]);
});

const names = (types) => types[0].fields.map((field) => field.name);

test("without PHP the PHP version picker goes too: it only said there was nothing to pick", () => {
  assert.deepEqual(names(withServableRenderingTypes([git()], ["node", "static"])), ["repository", "rendering_type"]);
  assert.deepEqual(names(withServableRenderingTypes([git()], ["php", "node", "static"])), ["repository", "php_version", "rendering_type"]);
  // Unknown profiles keep it, like the rendering types.
  assert.deepEqual(names(withServableRenderingTypes([git()], null)), ["repository", "php_version", "rendering_type"]);
});

test("client-side rendering follows static, not node: it is a directory of files", () => {
  assert.deepEqual(values(withServableRenderingTypes([git()], ["static"])), ["csr", "static"]);
});

test("unknown profiles hide nothing; the API's refusal is still there", () => {
  assert.deepEqual(values(withServableRenderingTypes([git()], null)), ["php", "ssr", "csr", "static"]);
  assert.deepEqual(values(withServableRenderingTypes([git()], [])), ["php", "ssr", "csr", "static"]);
});

test("a filter that would leave nothing keeps the field as it was, rather than an empty chooser", () => {
  assert.deepEqual(values(withServableRenderingTypes([git()], ["docker"])), ["php", "ssr", "csr", "static"]);
});

test("other types and fields are passed through untouched", () => {
  // A PHP type on a Node server is blocked by the availability check, not here.
  const wordpress = { name: "wordpress", fields: [{ name: "php_version", type: "select", source: "php_versions" }] };
  const [out] = withServableRenderingTypes([wordpress], ["node", "static"]);
  assert.equal(out, wordpress);
  const repository = withServableRenderingTypes([git()], ["node", "static"])[0].fields[0];
  assert.deepEqual(repository, { name: "repository", type: "select" });
});

test("the create page filters the types before the form sees them", async () => {
  const fs = await import("node:fs");
  const page = fs.readFileSync(new URL("../app/(app)/applications/create/page.jsx", import.meta.url), "utf8");
  assert.match(page, /withServableRenderingTypes\(types\.siteTypes, capabilities\?\.servingProfiles\)/);
});
