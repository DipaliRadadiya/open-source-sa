/**
 * Every label in the install-home map resolves, in every locale.
 *
 * The Services page builds these keys with template literals —
 * `t(\`attention.${home.label}\`)` — so they are invisible to grep and to the
 * key-resolution pass in check-i18n. A missing one does not fail the build or
 * the lint; it renders the raw key to a user who is already looking at a failed
 * install. This closes that gap by walking the map itself, so adding a service
 * home without its strings fails here.
 */
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { INSTALL_HOMES } from "../lib/services/install-home.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");
const locales = fs
  .readdirSync(path.join(root, "messages"))
  .filter((f) => f.endsWith(".json"))
  .map((f) => f.replace(/\.json$/, ""));

const problems = [];

for (const locale of locales) {
  const messages = JSON.parse(
    fs.readFileSync(path.join(root, "messages", `${locale}.json`), "utf8"),
  );
  const services = messages.services ?? {};

  for (const home of INSTALL_HOMES) {
    if (typeof services.attention?.[home.label] !== "string") {
      problems.push(`${locale}: services.attention.${home.label} missing`);
    }
    if (typeof services.state?.[home.retryLabel] !== "string") {
      problems.push(`${locale}: services.state.${home.retryLabel} missing`);
    }
  }
}

// A home pointing at a route that does not exist is the bug this whole module
// was written to fix, so it is worth asserting too.
// Route groups are parenthesised directory names that do not appear in the URL,
// so a segment can sit under any of them — /setup lives in (setup), the rest in
// (app). Look through every group rather than assuming one.
const groups = fs
  .readdirSync(path.join(root, "app"), { withFileTypes: true })
  .filter((entry) => entry.isDirectory() && entry.name.startsWith("("))
  .map((entry) => entry.name);

for (const home of INSTALL_HOMES) {
  const segment = home.href.replace(/^\//, "");
  const found = ["", ...groups].some((group) =>
    ["page.jsx", "page.js"].some((file) =>
      fs.existsSync(path.join(root, "app", group, segment, file)),
    ),
  );
  if (!found) {
    problems.push(`route ${home.href} has no page under app/`);
  }
}

if (problems.length) {
  console.error("install-home check FAILED:");
  for (const p of problems) console.error("  " + p);
  process.exit(1);
}

console.log(
  `install-home ok — ${INSTALL_HOMES.length} homes, ${locales.length} locales, every label resolves and every route exists`,
);
