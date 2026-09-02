/**
 * Fails the build when a component file is imported by nothing.
 *
 * Why this exists: this is plain JavaScript with no TypeScript, so a file that
 * lost its last importer keeps compiling, keeps passing lint, and keeps sitting
 * in the tree looking like working code. `components/logout-button.jsx` did
 * exactly that — 24 lines with a hardcoded English "Log out" that no i18n check
 * ever saw, because the component never rendered anywhere.
 *
 * The dead file itself is harmless. The blind spot is not: the same check that
 * would have found it is the one that would find a component still imported by
 * a page after being renamed — which fails only when someone opens that page.
 *
 * Scope is deliberately narrow — `components/` only:
 *   - `app/` files are routed by convention, not imported.
 *   - `lib/` and `hooks/` hold helpers a test may be the only caller of, and
 *     that is legitimate.
 * Anything under `components/ui/` is exempt: shadcn generates those on `add`,
 * and one sitting unused until a screen needs it is expected, not a mistake.
 */
import fs from "node:fs";
import path from "node:path";

const ROOTS = ["app", "components", "lib", "hooks", "tests"];
const SUBJECT = "components";
const EXEMPT = [path.join("components", "ui") + path.sep];
const SKIP_DIRS = new Set([".next", "node_modules", ".git"]);

function walk(dir, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (!SKIP_DIRS.has(entry.name)) walk(full, out);
    } else if (/\.(jsx|js|mjs)$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

const files = ROOTS.flatMap((r) => walk(r));
const source = new Map(files.map((f) => [f, fs.readFileSync(f, "utf8")]));

/**
 * Every import specifier in the tree, reduced to the bare module name.
 *
 * Matching by basename rather than resolving paths: an import can be written
 * `@/components/x/y`, `./y`, `../x/y`, or reached through `dynamic(() =>
 * import("..."))`, and a resolver that handles only some of those reports files
 * as dead when they are not — which is worse than no check, because the fix is
 * to delete something still in use.
 */
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

const orphans = files
  .filter((f) => f.startsWith(SUBJECT + path.sep))
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

console.log(`unreferenced ok — every file in ${SUBJECT}/ is imported somewhere`);
