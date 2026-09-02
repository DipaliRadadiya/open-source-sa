/**
 * Fails the build when a file declares `"use client"` that it does not need.
 *
 * A file reached only from other client components is already client code —
 * the directive above it changes nothing. 227 files carried one, against 132
 * that were real boundaries, so `grep "use client"` answered a question nobody
 * was asking: it named every file that happens to use a hook, not the line
 * where server rendering stops.
 *
 * The stripped build was byte-for-byte identical to the one before it, which is
 * the point — this check protects a property of the source, not of the output.
 * Keeping it honest matters because the boundary is the thing you have to
 * reason about when a page is slow, and 359 candidates for 132 answers is not a
 * list anyone reads.
 *
 * The directive is REQUIRED, and correct, when a Server Component imports the
 * file. Adding one to a file that is not a boundary is what this rejects, and
 * the fix is always to delete the line.
 */
import fs from "node:fs";
import path from "node:path";

const ROOTS = ["app", "components", "lib", "hooks", "i18n"];
const SKIP_DIRS = new Set([".next", "node_modules", ".git"]);

function walk(dir, out = []) {
  if (!fs.existsSync(dir)) return out;
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (!SKIP_DIRS.has(entry.name)) walk(full, out);
    } else if (/\.jsx?$/.test(entry.name)) {
      out.push(full);
    }
  }
  return out;
}

const files = ROOTS.flatMap((r) => walk(r));
const source = new Map(files.map((f) => [f, fs.readFileSync(f, "utf8")]));
const isClient = (f) => /^\s*["']use client["']/m.test(source.get(f) ?? "");

const withExtension = (base) => {
  for (const ext of ["", ".jsx", ".js", "/index.jsx", "/index.js"]) {
    if (source.has(base + ext)) return base + ext;
  }
  return null;
};

/**
 * Both import spellings, or the answer is wrong in the dangerous direction:
 * missing an importer makes a real boundary look redundant, and deleting that
 * directive breaks the build. `import()` is matched too — the four dashboard
 * charts are reached only that way.
 */
const resolve = (spec, from) => {
  if (spec.startsWith("@/")) return withExtension(spec.slice(2));
  if (spec.startsWith(".")) {
    return withExtension(path.normalize(path.join(path.dirname(from), spec)));
  }
  return null;
};

const importers = new Map();
for (const [file, text] of source) {
  for (const match of text.matchAll(/(?:from\s*|import\s*\(\s*)["']([.@][^"']+)["']/g)) {
    const target = resolve(match[1], file);
    if (!target || target === file) continue;
    if (!importers.has(target)) importers.set(target, new Set());
    importers.get(target).add(file);
  }
}

const redundant = files.filter((f) => {
  if (!isClient(f)) return false;
  // Route files are entered by Next rather than imported, so "who imports it"
  // cannot answer the question — a page marking itself client is its own call.
  if (f.startsWith("app" + path.sep)) return false;
  const who = [...(importers.get(f) ?? [])];
  return who.length > 0 && who.every(isClient);
});

if (redundant.length) {
  console.error(
    `\nclient boundary check failed — ${redundant.length} file(s) declare "use client" but are only ever imported by client components:\n`,
  );
  for (const f of redundant) console.error("  " + f);
  console.error(
    '\nDelete the directive. These files are already client code; the line only\nhides which files are the actual server/client boundary.',
  );
  process.exit(1);
}

const boundaries = files.filter((f) => isClient(f) && !f.startsWith("app" + path.sep)).length;
console.log(`client boundary ok — ${boundaries} real boundaries, no redundant directives`);
