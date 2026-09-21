import fs from "node:fs";
import path from "node:path";

/*
 * Every screen opens with the same heading component.
 *
 * Twenty-eight route files had `PageHeader`'s markup copied out by hand. That
 * is not a tidiness complaint: it is why the firewall page once had a title
 * that disagreed with its own sidebar label, why the settings layout sat on
 * `space-y-0.5` while everything else used `space-y-1`, and why "this page
 * looks different from the others" kept arriving one screenshot at a time.
 * None of those are visible in a diff — only in aggregate, which is what this
 * checks.
 *
 * The exceptions are deliberate and listed by name. A screen that genuinely
 * leads with something else (a site's name and status, an onboarding card, a
 * 404) should say so here rather than quietly drift.
 */

const ALLOWED = new Map([
  [
    "app/(app)/applications/[application]/page.jsx",
    "leads with the site's name, status and domain, not a page title",
  ],
  [
    "app/(app)/databases/[database]/page.jsx",
    "leads with the database name in mono, beside its engine",
  ],
  ["app/(setup)/setup/page.jsx", "onboarding card: an icon, then the heading"],
  ["components/sections/not-found-content.jsx", "centred 404, not a page shell"],
  [
    "components/integrations/storage/google-drive-callback.jsx",
    "centred OAuth result: the heading is the outcome, so each state owns it",
  ],
  ["components/ui/page-header.jsx", "the component itself"],
]);

// Comments first. `components/ui/card.jsx` explains in a docblock why a card
// title takes an `as` prop — "left whole pages with a single <h1>" — and a bare
// grep read its own explanation as the thing it forbids. Allow-listing the file
// would have been the wrong fix: it would then permit a real <h1> there too.
const code = (source) =>
  source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

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

const offenders = [];
const staleAllowances = [];

for (const file of [...walk("app"), ...walk("components")]) {
  const source = code(fs.readFileSync(file, "utf8"));
  const hasHeading = /<h1[\s>]/.test(source);
  const allowed = ALLOWED.has(file);

  if (hasHeading && !allowed) offenders.push(file);
  // An allowance that no longer describes anything is a comment pretending to
  // be a rule — it would silently permit a future <h1> in that file.
  if (allowed && !hasHeading) staleAllowances.push(file);
}

if (offenders.length === 0 && staleAllowances.length === 0) {
  const total = ALLOWED.size - 1;
  console.log(`page headers ok — every screen uses PageHeader, ${total} named exceptions`);
  process.exit(0);
}

if (offenders.length) {
  console.error(`page header check failed — ${offenders.length} file(s) hand-roll an <h1>:`);
  for (const file of offenders) console.error(`  ${file}`);
  console.error(`
Use <PageHeader title={...} subtitle={...} /> so every screen agrees about
where its title, subtitle and spacing live. If this screen genuinely leads
with something else, add it to ALLOWED in scripts/check-page-header.mjs with
the reason.`);
}

if (staleAllowances.length) {
  console.error(`\n${staleAllowances.length} allowance(s) no longer have an <h1> — remove them:`);
  for (const file of staleAllowances) console.error(`  ${file}`);
}

process.exit(1);
