/**
 * Fails lint when translations drift. Checks:
 *   1. Every literal `t("…")` resolves in one of the file's own namespaces.
 *   2. Every locale has exactly English's key set.
 *   3. No key is defined twice in one object (`JSON.parse` silently keeps the last).
 *   4. Every locale string has English's ICU shape: same arguments, types and tags.
 *   5. An English string used at several keys is translated one way (scripts/one-voice.mjs).
 * Dynamic keys (`t(\`add.errors.${x}\`)`) can't be resolved statically and are skipped.
 */
import fs from "node:fs";
import path from "node:path";

import { duplicateKeys } from "./duplicate-keys.mjs";
import { shapeProblems } from "./icu-shape.mjs";
import { oneVoiceProblems } from "./one-voice.mjs";

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
    // Base and override namespaces; a key may live in either.
    ...[...src.matchAll(/useSubjectTranslations\(\s*"([^"]+)",\s*"([^"]+)"/g)].flatMap((m) => [m, [null, m[2]]]),
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

// 4 — the shape inside each string, not just the key that holds it
const value = (obj, key) =>
  key.split(".").reduce((o, part) => (o && typeof o === "object" ? o[part] : undefined), obj);

const englishStrings = {};
for (const key of base) {
  const source = value(en, key);
  if (typeof source === "string") englishStrings[key] = source;
}

for (const file of fs.readdirSync(MESSAGES).filter((f) => f.endsWith(".json") && f !== "en.json")) {
  const locale = path.basename(file, ".json");
  const messages = JSON.parse(fs.readFileSync(path.join(MESSAGES, file), "utf8"));
  const localeStrings = {};

  for (const key of base) {
    const source = value(en, key);
    const translated = value(messages, key);
    if (typeof source !== "string" || typeof translated !== "string") continue;
    localeStrings[key] = translated;
    for (const problem of shapeProblems(source, translated)) {
      problems.push(`${locale}: ${key} — ${problem}`);
    }
  }

  // 5 — one English string, one translation
  for (const problem of oneVoiceProblems(englishStrings, localeStrings, locale)) {
    problems.push(`${locale}: ${problem}`);
  }
}

if (problems.length) {
  console.error(`\ni18n check failed — ${problems.length} problem(s):\n`);
  for (const p of problems.slice(0, 40)) console.error("  " + p);
  if (problems.length > 40) console.error(`  …and ${problems.length - 40} more`);
  process.exit(1);
}

console.log("i18n ok — all keys resolve, all locales in sync");
