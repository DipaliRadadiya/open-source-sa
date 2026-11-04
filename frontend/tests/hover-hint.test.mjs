import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

// Krishna (4 Nov): hover text uses the panel's tooltip, never the browser's own `title` bubble.
const root = new URL("..", import.meta.url).pathname;
const files = [];
for (const dir of ["components", "app"]) {
  (function walk(d) {
    for (const e of fs.readdirSync(d, { withFileTypes: true })) {
      const p = path.join(d, e.name);
      if (e.isDirectory()) walk(p);
      else if (/\.(jsx|js)$/.test(e.name)) files.push(p);
    }
  })(path.join(root, dir));
}

test("no element shows a native title tooltip", () => {
  // DOM elements, and the components that hand `title` straight to the DOM.
  const opening = /<([a-z][a-z0-9]*|Link|Button|Badge|Icon)\b((?:[^<>]|\{[^{}]*\})*?)\btitle=/g;
  const allowed = new Set(["title", "iframe", "svg", "abbr"]);
  const found = [];
  for (const file of files) {
    const src = fs.readFileSync(file, "utf8");
    for (const m of src.matchAll(opening)) {
      if (allowed.has(m[1])) continue;
      found.push(`${path.relative(root, file)}:${src.slice(0, m.index).split("\n").length} <${m[1]}>`);
    }
  }
  assert.deepEqual(found, []);
});

test("HoverHint adds no element and stays silent without content", () => {
  const src = fs.readFileSync(path.join(root, "components/ui/hover-hint.jsx"), "utf8");
  assert.match(src, /<TooltipTrigger asChild>\{children\}<\/TooltipTrigger>/);
  assert.match(src, /if \(content === null \|\| content === undefined \|\| content === false \|\| content === ""\) return children;/);
  // Its own provider: the setup and sign-in layouts have none.
  assert.match(src, /<TooltipProvider delayDuration=\{300\}>/);
});
