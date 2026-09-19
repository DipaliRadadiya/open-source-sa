import fs from "node:fs";
import path from "node:path";

/*
 * Every error box names the failure.
 *
 * `LoadFailed` takes `status` and `failure` and turns them into a sentence
 * someone can act on: a 403 is a permissions problem, a 500 is the backend's,
 * a shape mismatch is ours. Without them it renders "This part could not be
 * loaded" — a shrug — and no code, which is the one thing anyone reporting the
 * problem would quote.
 *
 * 39 of 65 call sites passed neither. Krishna hit one on the Restores tab and
 * asked why; the honest answer was that the panel knew and had thrown it away,
 * and no amount of reading the screenshot could recover it. Every fetcher has
 * carried both fields since `read()` was consolidated — the gap was always at
 * the call site.
 *
 * This checks the props are present, not that they are the RIGHT ones. A box
 * handed the wrong read's status is still wrong, and only reading the guard
 * beside it can catch that.
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

// Comments masked, not stripped, so line numbers stay true — the same trap
// that has caught three checks in this repo: a docblock quoting the thing it
// is explaining, read as a fresh offence.
const mask = (s) =>
  s
    .replace(/\/\*[\s\S]*?\*\//g, (m) => m.replace(/[^\n]/g, " "))
    .replace(/^[ \t]*\/\/.*$/gm, (m) => " ".repeat(m.length));

const offenders = [];
let total = 0;

for (const file of [...walk("app"), ...walk("components")]) {
  const source = mask(fs.readFileSync(file, "utf8"));
  // Self-closing only: LoadFailed takes no children.
  for (const match of source.matchAll(/<LoadFailed\b[^>]*?\/>/gs)) {
    total += 1;
    const missing = ["status", "failure"].filter((p) => !match[0].includes(`${p}=`));
    if (missing.length) {
      offenders.push({
        file,
        line: source.slice(0, match.index).split("\n").length,
        missing,
      });
    }
  }
}

if (offenders.length === 0) {
  console.log(`load-failed ok — ${total} error boxes, every one names its failure`);
  process.exit(0);
}

console.error(`load-failed check failed — ${offenders.length} of ${total} box(es) say nothing:`);
for (const { file, line, missing } of offenders) {
  console.error(`  ${file}:${line}  missing ${missing.join(" and ")}`);
}
console.error(`
Pass them from the read that failed:

  const { data, failed, status, failure } = await getThing();
  if (failed) return <LoadFailed description={t("loadFailed")} status={status} failure={failure} />;

They come from \`read()\` and every fetcher already returns them. A box without
them cannot tell a 403 from a 500, and neither can the person reading it.`);
process.exit(1);
