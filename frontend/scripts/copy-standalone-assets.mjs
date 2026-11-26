/**
 * Copy `public/` and `.next/static` into `.next/standalone/`, which ships without them.
 * Chained onto `build` with `&&`, not `postbuild`: the documented
 * `npm ci --ignore-scripts && npm run build` would skip a lifecycle hook.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

/*
 * The project root is derived from THIS FILE's location, not from
 * `process.cwd()`.
 *
 * cwd is whatever the caller happened to be in. `npm run build` usually sets
 * it to the package directory, but a wrapper, a version manager shim or an
 * `npm --prefix` invocation need not, and when it is wrong this script
 * cheerfully copies nothing from a directory that does not exist and exits 0 —
 * which is the silent failure all over again, one level down.
 */
const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const standalone = path.join(root, ".next", "standalone");

// Not a standalone build — `next start` serves both directories itself, and
// there is nothing to copy to.
if (!fs.existsSync(standalone)) {
  console.log(`build: no ${standalone} — nothing to copy`);
  process.exit(0);
}

const copies = [
  { from: path.join(root, "public"), to: path.join(standalone, "public") },
  { from: path.join(root, ".next", "static"), to: path.join(standalone, ".next", "static") },
];

for (const { from, to } of copies) {
  if (!fs.existsSync(from)) {
    console.log(`build: ${from} does not exist — skipped`);
    continue;
  }
  // Replaced rather than merged: a file deleted from `public` between builds
  // would otherwise be served forever by the copy nobody cleaned up.
  fs.rmSync(to, { recursive: true, force: true });
  fs.cpSync(from, to, { recursive: true });

  const count = fs.readdirSync(from, { recursive: true }).length;
  console.log(`build: ${from} → ${to} (${count} entries)`);
}
