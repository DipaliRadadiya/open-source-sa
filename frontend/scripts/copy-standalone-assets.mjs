// Copies public/ and .next/static into .next/standalone/. Chained with && rather than postbuild, because --ignore-scripts skips lifecycle hooks.
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const standalone = path.join(root, ".next", "standalone");

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
  // Replace, don't merge, or files deleted from public/ are served forever.
  fs.rmSync(to, { recursive: true, force: true });
  fs.cpSync(from, to, { recursive: true });

  const count = fs.readdirSync(from, { recursive: true }).length;
  console.log(`build: ${from} → ${to} (${count} entries)`);
}
