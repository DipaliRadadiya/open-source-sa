import fs from "node:fs";
import path from "node:path";

/*
 * A card's vertical padding is set once. `Card` has its own `py`; a `CardContent` with `py-*`
 * adds to it. Exactly one of the pair may set it, as in:
 *
 *     <Card className="gap-0 overflow-hidden py-0 shadow-sm">
 *       <CardContent className="px-5 py-4">
 */

function walk(dir, out = []) {
  for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) {
      if (entry.name === "node_modules" || entry.name === ".next") continue;
      walk(full, out);
    } else if (entry.name.endsWith(".jsx")) {
      out.push(full);
    }
  }
  return out;
}

// Comments masked, not stripped, so line numbers stay true — this file's own
// docblock contains the exact markup it is looking for.
const mask = (s) =>
  s
    .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, " "))
    .replace(/^[ \t]*\/\/.*$/gm, (m) => " ".repeat(m.length));

// `py-0` cancels; any other py-* applies. `p-4` sets all four sides, so it
// counts as applying vertical padding too.
const appliesY = (cls) => {
  const py = [...cls.matchAll(/(?<![\w:-])p(?:y)?-([\w.[\]]+)/g)].map((m) => m[1]);
  if (py.length === 0) return null; // says nothing — inherits
  return py[py.length - 1] !== "0";
};

const offenders = [];
let pairs = 0;

for (const file of walk("components")) {
  const source = mask(fs.readFileSync(file, "utf8"));

  for (const match of source.matchAll(/<CardContent\s+className="([^"]*)"/g)) {
    const contentSetsY = appliesY(match[1]);
    if (contentSetsY !== true) continue;

    // The Card that opens above it.
    const before = source.slice(0, match.index);
    const cards = [...before.matchAll(/<Card\b[^>]*?>/gs)];
    if (cards.length === 0) continue;

    pairs += 1;
    const card = cards[cards.length - 1][0];
    // The Card must have handed its padding over.
    if (!/(?<![\w:-])py-0(?![\w.])/.test(card)) {
      offenders.push({ file, line: before.split("\n").length });
    }
  }
}

if (offenders.length === 0) {
  console.log(`card padding ok — ${pairs} content-padded cards, none doubled`);
  process.exit(0);
}

console.error(`card padding check failed — ${offenders.length} card(s) pad twice:`);
for (const { file, line } of offenders) console.error(`  ${file}:${line}`);
console.error(`
A Card already has \`py-(--card-spacing)\`. A CardContent with its own \`py-*\`
adds a second one, so the card renders at double the height every other card has.

Hand the padding over on the Card, the way the other fifteen do:

  <Card className="gap-0 overflow-hidden py-0 shadow-sm">
    <CardContent className="px-5 py-4">`);
process.exit(1);
