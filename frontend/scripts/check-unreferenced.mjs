/**
 * Fails lint when a file under `components/` is imported by nothing (plain JS keeps compiling
 * a dead file). `app/` is routed by convention, `lib/` and `hooks/` may be test-only, and
 * `components/ui/` holds shadcn primitives that may wait unused.
 */
import fs from "node:fs";
import path from "node:path";

const ROOTS = ["app", "components", "lib", "hooks", "tests"];
const SUBJECTS = ["components"];

// CSS modules are checked everywhere, not just under `components/`.
const STYLE_SUBJECTS = ROOTS;
const EXEMPT = [path.join("components", "ui") + path.sep];
const SKIP_DIRS = new Set([".next", "node_modules", ".git"]);

function walk(dir, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (!SKIP_DIRS.has(entry.name)) walk(full, out);
    } else if (/\.(jsx|js|mjs|module\.css)$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

const files = ROOTS.flatMap((r) => walk(r));
const source = new Map(files.map((f) => [f, fs.readFileSync(f, "utf8")]));

// Every import specifier in the tree, reduced to its basename, so `@/…`, relative and
// `dynamic(() => import(…))` forms all count without a resolver.
const referenced = new Set();
for (const [file, text] of source) {
  for (const match of text.matchAll(/["']([^"']*\/[^"'/]+|\.\/[^"'/]+)["']/g)) {
    const spec = match[1];
    if (!/^[.@]/.test(spec)) continue;
    referenced.add(path.basename(spec).replace(/\.(jsx|js|mjs)$/, ""));
    // Only same-file references would otherwise count a file as its own importer.
    if (file === spec) continue;
  }
}

const inScope = (f) =>
  f.endsWith(".module.css")
    ? STYLE_SUBJECTS.some((r) => f.startsWith(r + path.sep))
    : SUBJECTS.some((r) => f.startsWith(r + path.sep));

const orphans = files
  .filter(inScope)
  .filter((f) => !EXEMPT.some((e) => f.startsWith(e)))
  .filter((f) => {
    const name = path.basename(f).replace(/\.(jsx|js|mjs)$/, "");
    // A file naming itself in its own import list does not make it referenced.
    const others = [...source].filter(([other]) => other !== f);
    return !others.some(([, text]) =>
      new RegExp(`["'][^"']*(?:/|^)${name}["']`).test(text),
    );
  });

if (orphans.length) {
  console.error(`\nunreferenced check failed — ${orphans.length} file(s) imported by nothing:\n`);
  for (const f of orphans) console.error("  " + f);
  console.error("\nDelete them, or wire them in. A file nothing imports cannot be tested.");
  process.exit(1);
}

console.log(
  `unreferenced ok — every component and every stylesheet is imported somewhere`,
);
