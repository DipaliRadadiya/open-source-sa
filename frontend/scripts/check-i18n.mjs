/**
 * Fails the build when a translation key is used but missing, or when a locale
 * has drifted from English.
 *
 * Why this exists: a missing next-intl key does not break the build. It renders
 * the key itself — `FIREWALL.ADD.VERBLABEL` — in the running UI. That shipped
 * twice in one day (2026-07-30), both times with a green build, and both times
 * it was caught only because someone happened to run a check by hand. A check
 * that depends on remembering to run it is not a check.
 *
 * Three things are verified:
 *   1. Every literal `t("…")` resolves in one of the file's own namespaces.
 *   2. Every locale has exactly the same key set as English — no missing keys
 *      falling back silently, no orphans left behind after a rename.
 *   3. No key is defined twice in the same object. `JSON.parse` keeps the last
 *      one and discards the rest without a word, so the edit you just made can
 *      be dead on arrival and every other check still passes: the key set is
 *      identical, it resolves, the build is green. `validation.max500` sat
 *      duplicated in all three locales until a round-trip happened to show it.
 *
 * Dynamic keys (`t(\`add.errors.${x}\`)`) are skipped: they can't be resolved
 * statically, so they stay a human responsibility.
 */
import fs from "node:fs";
import path from "node:path";

import { duplicateKeys } from "./duplicate-keys.mjs";

const MESSAGES = "messages";
const SOURCE_DIRS = ["app", "components", "lib"];
const SKIP_DIRS = new Set([".next", "node_modules", ".git"]);

const en = JSON.parse(fs.readFileSync(path.join(MESSAGES, "en.json"), "utf8"));

const flatten = (obj, prefix = "") =>
  Object.entries(obj).flatMap(([k, v]) =>
    v && typeof v === "object" ? flatten(v, `${prefix}${k}.`) : [`${prefix}${k}`],
  );

const resolves = (key) =>
  key.split(".").reduce((o, part) => (o && typeof o === "object" ? o[part] : undefined), en) !==
  undefined;

function walk(dir) {
  if (!fs.existsSync(dir)) return [];
  return fs.readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return SKIP_DIRS.has(entry.name) ? [] : walk(full);
    return /\.(jsx|js)$/.test(entry.name) ? [full] : [];
  });
}

const problems = [];

// 1 — used but missing
for (const file of SOURCE_DIRS.flatMap(walk)) {
  const src = fs.readFileSync(file, "utf8");
  const namespaces = [
    ...src.matchAll(/(?:useTranslations|getTranslations)\(\s*"([^"]+)"\s*\)/g),
  ].map((m) => m[1]);
  if (!namespaces.length) continue;

  for (const match of src.matchAll(/\bt(?:\.rich)?\(\s*"([^"${}]+)"/g)) {
    const key = match[1];
    if (!namespaces.some((ns) => resolves(`${ns}.${key}`))) {
      problems.push(`${file}: t("${key}") does not resolve in ${namespaces.join(" | ")}`);
    }
  }
}

// 2 — locale drift
const base = new Set(flatten(en));
for (const file of fs.readdirSync(MESSAGES).filter((f) => f.endsWith(".json") && f !== "en.json")) {
  const locale = path.basename(file, ".json");
  const other = new Set(flatten(JSON.parse(fs.readFileSync(path.join(MESSAGES, file), "utf8"))));
  for (const key of base) if (!other.has(key)) problems.push(`${locale}: missing ${key}`);
  for (const key of other) if (!base.has(key)) problems.push(`${locale}: orphan ${key}`);
}

// 3 — a key defined twice in the same object
for (const file of fs.readdirSync(MESSAGES).filter((f) => f.endsWith(".json"))) {
  const locale = path.basename(file, ".json");
  for (const { name, line, first } of duplicateKeys(
    fs.readFileSync(path.join(MESSAGES, file), "utf8"),
  )) {
    problems.push(
      `${locale}: "${name}" is defined twice in the same object ` +
        `(lines ${first} and ${line}) — line ${first} is silently discarded`,
    );
  }
}

if (problems.length) {
  console.error(`\ni18n check failed — ${problems.length} problem(s):\n`);
  for (const p of problems.slice(0, 40)) console.error("  " + p);
  if (problems.length > 40) console.error(`  …and ${problems.length - 40} more`);
  process.exit(1);
}

console.log("i18n ok — all keys resolve, all locales in sync");
