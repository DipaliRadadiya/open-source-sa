import fs from "node:fs";
import path from "node:path";

/*
 * Nothing in the panel is smaller than 12px.
 *
 * 47 places used `text-[10px]` or `text-[11px]` — sidebar badges, service
 * cards, worker metadata, upload progress, stack traces, log chrome. Each one
 * looked reasonable on the screen it was written on. Together they made a
 * product that is hard to read at 200% zoom, on a laptop panel, or by anyone
 * who does not have young eyes, and none of it was a decision anyone made:
 * `text-[11px]` is what you reach for when `text-xs` feels one notch too big.
 *
 * 12px is `text-xs`, the scale's own bottom step. The rule is therefore also a
 * rule against arbitrary type sizes: if the answer is a number in brackets, the
 * scale is being stepped around rather than used.
 */

const FLOOR = 12;

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === "node_modules" || entry.name === ".next") continue;
      walk(full, out);
    } else if (entry.name.endsWith(".jsx") || entry.name.endsWith(".js")) {
      out.push(full);
    }
  }
  return out;
}

// Comments masked, not stripped, so reported line numbers stay true. A docblock
// explaining why a size CHANGED quotes the old one — `disk-io-chart.jsx` says
// "It was text-[10px] at 80% opacity" — and a bare grep reads a component's own
// history as a fresh offence.
const mask = (s) =>
  s
    .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, " "))
    .replace(/^[ \t]*\/\/.*$/gm, (m) => " ".repeat(m.length));

const offenders = [];

for (const file of [...walk("app"), ...walk("components")]) {
  const source = mask(fs.readFileSync(file, "utf8"));
  const lines = source.split("\n");
  lines.forEach((line, index) => {
    for (const match of line.matchAll(/text-\[(\d+(?:\.\d+)?)px\]/g)) {
      if (Number(match[1]) < FLOOR) {
        offenders.push({ file, line: index + 1, size: match[1] });
      }
    }
  });
}

if (offenders.length === 0) {
  console.log(`type floor ok — nothing below ${FLOOR}px`);
  process.exit(0);
}

console.error(`type floor check failed — ${offenders.length} use(s) below ${FLOOR}px:`);
for (const { file, line, size } of offenders) {
  console.error(`  ${file}:${line}  text-[${size}px]`);
}
console.error(`
Use \`text-xs\` (12px) or larger. 12px is the bottom of the scale, and a size
in brackets means the scale is being stepped around rather than used.`);
process.exit(1);
