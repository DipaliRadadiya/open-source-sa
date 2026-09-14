import assert from "node:assert/strict";
import test from "node:test";
import fs from "node:fs";
import path from "node:path";
import { MANY_MEANINGS, oneVoiceProblems } from "../scripts/one-voice.mjs";

/*
 * The defect that survives every other check.
 *
 * German was translated in 22 parallel batches. Each batch was correct on its
 * own: complete key set, intact placeholders, good sentences. Together they
 * said "Saving…" two ways across nineteen buttons, and called RAM by the word
 * a German reader uses for disk — on a panel that shows both on one page. Key
 * parity, ICU shape, eslint and the build were all green throughout.
 *
 * These cases are mostly about the line between an inconsistency and a
 * language doing its job. "Custom" has to inflect for gender in German,
 * Spanish, Portuguese and Russian, so demanding one rendering would be
 * demanding every language have English's grammar — the same mistake the ICU
 * check makes if it insists on English's plural categories.
 */

const flatten = (value, prefix = "", out = {}) => {
  for (const [key, child] of Object.entries(value)) {
    const full = prefix ? `${prefix}.${key}` : key;
    if (child && typeof child === "object") flatten(child, full, out);
    else out[full] = child;
  }
  return out;
};

const read = (locale) =>
  flatten(JSON.parse(fs.readFileSync(path.join("messages", `${locale}.json`), "utf8")));

test("one English string rendered two ways is reported", () => {
  const problems = oneVoiceProblems(
    { "a.save": "Saving…", "b.save": "Saving…" },
    { "a.save": "Speichert…", "b.save": "Wird gespeichert…" },
  );
  assert.equal(problems.length, 1);
  assert.match(problems[0], /"Saving…" is translated 2 ways/);
});

test("the report names a key for each rendering, so it can be found", () => {
  const [problem] = oneVoiceProblems(
    { "services.memoryShort": "Memory", "serverDashboard.memory": "Memory" },
    { "services.memoryShort": "Speicher", "serverDashboard.memory": "Arbeitsspeicher" },
  );
  assert.match(problem, /services\.memoryShort/);
  assert.match(problem, /serverDashboard\.memory/);
});

test("one English string rendered one way is fine", () => {
  const problems = oneVoiceProblems(
    { "a.save": "Saving…", "b.save": "Saving…" },
    { "a.save": "Wird gespeichert…", "b.save": "Wird gespeichert…" },
  );
  assert.deepEqual(problems, []);
});

test("a string used once cannot be inconsistent with itself", () => {
  assert.deepEqual(oneVoiceProblems({ "a.x": "Delete" }, { "a.x": "Löschen" }), []);
});

test("a string with a reason to differ is exempt", () => {
  // "Right now" is the current value in one place and the soonest option in
  // another. One rendering would be wrong in one of them.
  const problems = oneVoiceProblems(
    { "swap.current": "Right now", "reboot.now": "Right now" },
    { "swap.current": "Aktuell", "reboot.now": "Sofort" },
  );
  assert.deepEqual(problems, []);
});

test("every exemption states why", () => {
  for (const [source, exemption] of Object.entries(MANY_MEANINGS)) {
    const { reason } = exemption;
    assert.equal(typeof reason, "string", `${source} has no reason`);
    assert.ok(reason.length > 30, `${source}'s reason is too short to be one: "${reason}"`);
  }
});

test("an exemption names real locales, or none at all", () => {
  // A typo'd tag would silently grant nobody the pass and report the locale
  // that needs it, which reads as the translation being wrong.
  const shipped = new Set(
    fs
      .readdirSync("messages")
      .filter((file) => file.endsWith(".json"))
      .map((file) => path.basename(file, ".json")),
  );
  for (const [source, { locales }] of Object.entries(MANY_MEANINGS)) {
    if (locales === undefined) continue;
    assert.ok(Array.isArray(locales) && locales.length > 0, `${source} has an empty locale list`);
    for (const locale of locales) {
      assert.ok(shipped.has(locale), `${source} is exempt for "${locale}", which has no messages`);
    }
  }
});

test("a scoped exemption lets the language that inflects split the string", () => {
  // Spanish agrees the participle with the noun: a database is feminine, a
  // system user masculine.
  const problems = oneVoiceProblems(
    { "databases.created": "Created", "systemUsers.created": "Created" },
    { "databases.created": "Creada", "systemUsers.created": "Creado" },
    "es",
  );
  assert.deepEqual(problems, []);
});

test("a scoped exemption does not cover a language with no need of it", () => {
  // The whole point of the scope. German has one word for this and must use it.
  const [problem] = oneVoiceProblems(
    { "databases.created": "Created", "systemUsers.created": "Created" },
    { "databases.created": "Erstellt", "systemUsers.created": "Angelegt" },
    "de",
  );
  assert.match(problem, /"Created" is translated 2 ways/);
});

test("a caller that does not name its locale gets only the unscoped exemptions", () => {
  const named = { "a.x": "Created", "b.x": "Created" };
  const split = { "a.x": "Creada", "b.x": "Creado" };
  assert.equal(oneVoiceProblems(named, split).length, 1);
});

test("an untranslated key is skipped rather than counted as a rendering", () => {
  // A locale mid-edit can be missing a key. That is check 2's job to report;
  // this one must not also claim the string is inconsistent.
  const problems = oneVoiceProblems(
    { "a.save": "Saving…", "b.save": "Saving…" },
    { "a.save": "Wird gespeichert…" },
  );
  assert.deepEqual(problems, []);
});

test("German says each English string one way", () => {
  // The locale this check was written for. Not a sample — the whole file.
  assert.deepEqual(oneVoiceProblems(read("en"), read("de"), "de"), []);
});

test("every shipped locale says each English string one way", () => {
  // Spanish and Hindi were pinned at 60 and 108 when this check was written,
  // because they predate it. Nothing is pinned now — a pin has to be lowered by
  // hand, and one nobody lowers reads as a clean bill of health.
  const locales = fs
    .readdirSync("messages")
    .filter((file) => file.endsWith(".json"))
    .map((file) => path.basename(file, ".json"))
    .filter((locale) => locale !== "en");

  const en = read("en");
  for (const locale of locales) {
    assert.deepEqual(oneVoiceProblems(en, read(locale), locale), [], `${locale} is inconsistent`);
  }
});
