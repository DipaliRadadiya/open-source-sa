import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

/*
 * Krishna asked me to remove "121 helper texts that just repeat their own
 * title". That number was mine and it was wrong — re-derived, the panel has
 * 262 title+description pairs and almost all of them carry real information.
 * Four did not, and they were all the same shape:
 *
 *   No matching applications          <- title
 *   No applications match your search. <- description
 *   [ Clear search ]                   <- action
 *
 * Three statements of one fact. The other five filtered empty states name
 * WHICH filters are in play ("Try a different user or status") on screens that
 * have more than one, which is worth a line; these four had only a search box.
 *
 * So the rule pinned here is not "empty states have no description" — it is
 * that a description may not restate its own title.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);
const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]),
);

test("the description is optional, so dropping one leaves no empty paragraph", () => {
  /*
   * It was rendered unconditionally. Passing nothing produced an empty <p>
   * that still opened `space-y-1`, so the gap stayed behind the sentence.
   */
  const src = read("components/data-table/empty-state.jsx");
  assert.match(src, /\{description \? \(/);
  assert.match(src, /\) : null\}/);
});

test("the four that only rephrased their title are gone from every locale", () => {
  const gone = [
    ["applications", "empty", "filteredDescription"],
    ["applications", "files", "empty", "filteredDescription"],
    ["databases", "empty", "filteredDesc"],
    ["systemUsers", "empty", "filteredDesc"],
  ];
  for (const locale of LOCALES) {
    for (const keyPath of gone) {
      let node = messages[locale];
      for (const part of keyPath.slice(0, -1)) node = node?.[part];
      assert.equal(
        node?.[keyPath.at(-1)],
        undefined,
        `${locale} still carries ${keyPath.join(".")}`,
      );
    }
  }
});

test("the title still names the thing, now that nothing below it does", () => {
  // "No matches" was only specific because the description said "database".
  for (const locale of LOCALES) {
    const title = messages[locale].databases.empty.filteredTitle;
    assert.ok(title?.trim(), `${locale} databases.empty.filteredTitle`);
  }
  assert.equal(messages.en.databases.empty.filteredTitle, "No matching databases");
});

test("no empty-state description restates its own title", () => {
  /*
   * The guard, not the cleanup. Word overlap is a crude measure and it is used
   * crudely on purpose: it only fires when the description adds at most one
   * idea the title did not already contain.
   */
  const files = [];
  const walk = (dir) => {
    for (const entry of fs.readdirSync(dir, { withFileTypes: true })) {
      const p = path.join(dir, entry.name);
      if (entry.isDirectory()) {
        if (!/node_modules|\.next/.test(p)) walk(p);
      } else if (p.endsWith(".jsx")) files.push(p);
    }
  };
  walk("components");
  walk("app");

  const resolve = (ns, key) => {
    const full = key.startsWith(".") ? key.slice(1) : ns ? `${ns}.${key}` : key;
    let node = messages.en;
    for (const part of full.split(".")) {
      if (node && typeof node === "object" && part in node) node = node[part];
      else return null;
    }
    return typeof node === "string" ? node : null;
  };

  const STOP = new Set(
    "a an the and or of for to in on is are this that your you it its with by from as at be can will into per each all any".split(" "),
  );
  const words = (s) =>
    s
      .toLowerCase()
      .replace(/\{[^}]*\}/g, " ")
      .replace(/<[^>]*>/g, " ")
      .replace(/[^a-z0-9 ]/g, " ")
      .split(/\s+/)
      .filter((w) => w && !STOP.has(w))
      .map((w) => w.replace(/ies$/, "y").replace(/(es|s)$/, "").replace(/(ing|ed)$/, ""));

  const offenders = [];
  for (const file of files) {
    const src = read(file);
    const ns = (src.match(/useTranslations\(\s*"([^"]+)"\s*\)/) ?? [])[1] ?? null;
    for (const block of src.split("<EmptyState").slice(1)) {
      const head = block.slice(0, 700);
      const titleKey = head.match(/\btitle=\{t\("([^"]+)"/)?.[1];
      const descKey = head.match(/\bdescription=\{t\("([^"]+)"/)?.[1];
      if (!titleKey || !descKey) continue;
      const title = resolve(ns, titleKey);
      const desc = resolve(ns, descKey);
      if (!title || !desc) continue;

      const titleWords = new Set(words(title));
      const descWords = words(desc);
      const covered = [...titleWords].every((w) => descWords.includes(w));
      const novel = new Set(descWords.filter((w) => !titleWords.has(w))).size;
      if (titleWords.size && covered && novel <= 1) {
        offenders.push(`${file}: "${title}" / "${desc}"`);
      }
    }
  }
  assert.deepEqual(offenders, [], `an empty-state description repeats its title:\n${offenders.join("\n")}`);
});
