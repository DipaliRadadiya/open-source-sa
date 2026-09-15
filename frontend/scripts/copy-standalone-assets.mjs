/**
 * Put `public/` and `.next/static` where the standalone server can serve them.
 *
 * `output: 'standalone'` traces the server's own dependencies and nothing
 * else: `.next/standalone/` ships without either directory, by design. Next
 * documents the copy as a deployment step, which is fine until the deployment
 * step is a person.
 *
 * It bit on 2026-09-15. The panel had never served a file from `public/` —
 * every icon until then was an inline Lucide glyph — so the omission was
 * invisible for the life of the project. The first commit to add real image
 * files shipped a build where every one of them 404'd, on a server whose
 * `npm ci && npm run build && restart` had always been enough. Ours only
 * worked because the systemd unit happens to copy them in ExecStartPre; a
 * second install with a plainer unit showed blank rows and a green build.
 *
 * Chained onto `build` with `&&` rather than living in `postbuild`, because
 * `npm run build --ignore-scripts` skips lifecycle hooks silently — and the
 * documented restart for this panel is `npm ci --ignore-scripts && npm run
 * build`. A hook would have reproduced the same invisible failure it exists to
 * prevent, on the exact command most likely to be used.
 */
import fs from "node:fs";
import path from "node:path";

const root = process.cwd();
const standalone = path.join(root, ".next", "standalone");

// Not a standalone build — `next start` serves both directories itself, and
// there is nothing to copy to.
if (!fs.existsSync(standalone)) {
  console.log("build: no .next/standalone — nothing to copy");
  process.exit(0);
}

const copies = [
  { from: path.join(root, "public"), to: path.join(standalone, "public") },
  { from: path.join(root, ".next", "static"), to: path.join(standalone, ".next", "static") },
];

for (const { from, to } of copies) {
  if (!fs.existsSync(from)) {
    console.log(`build: ${path.relative(root, from)} does not exist — skipped`);
    continue;
  }
  // Replaced rather than merged: a file deleted from `public` between builds
  // would otherwise be served forever by the copy nobody cleaned up.
  fs.rmSync(to, { recursive: true, force: true });
  fs.cpSync(from, to, { recursive: true });

  const count = fs.readdirSync(from, { recursive: true }).length;
  console.log(`build: ${path.relative(root, from)} → ${path.relative(root, to)} (${count} entries)`);
}
