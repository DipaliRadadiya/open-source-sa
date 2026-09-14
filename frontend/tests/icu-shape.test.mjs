import assert from "node:assert/strict";
import test from "node:test";
import fs from "node:fs";
import path from "node:path";
import { messageShape, shapeProblems } from "../scripts/icu-shape.mjs";

/*
 * The check that lets eight locales exist.
 *
 * Key parity stops at the key. What breaks inside a string — a dropped
 * `{count}`, a renamed argument, a plural that lost its `other` branch — passes
 * the key check, passes eslint and passes the build, then throws or renders a
 * hole in front of a user.
 *
 * Half these cases are about NOT crying wolf. The first version of the parser
 * treated every brace alike, so the `one {No apps}` branch of a plural read as
 * an argument named `No`, and it reported 418 problems against two locales that
 * were correct. A check that fires on good input gets ignored, which is worse
 * than not having one.
 */

// Real strings from messages/en.json.
const APPS_COUNT = "{count, plural, =0 {No apps} one {1 app} other {# apps}}";
const VERSIONS_ADDED = "{runtime} {versions} {count, plural, one {is} other {are}} now available.";

test("a plain placeholder is found, and a branch keyword is not", () => {
  const { args } = messageShape("Server default PHP {version} is required for {name}.");
  assert.deepEqual([...args.keys()].sort(), ["name", "version"]);
});

test("a plural's branch text is text, not arguments", () => {
  // "No apps" inside `=0 {…}` is the whole reason this parser is recursive.
  const { args } = messageShape(APPS_COUNT);
  assert.deepEqual([...args], [["count", "plural"]]);
});

test("an argument nested inside a branch is still an argument", () => {
  const { args } = messageShape(
    "{count, plural, one {one site on {server}} other {# sites on {server}}}",
  );
  assert.deepEqual([...args].sort(), [["count", "plural"], ["server", ""]]);
});

test("apostrophes quote braces, as ICU says and every Romance locale needs", () => {
  // French and Spanish are full of these. A naive scan reads `{name}` here.
  assert.deepEqual([...messageShape("Le mot '{name}' est litteral").args.keys()], []);
  // And an ordinary apostrophe is not quoting anything.
  assert.deepEqual([...messageShape("L'application {name} est prête").args.keys()], ["name"]);
});

test("rich-text tags are collected from both ends", () => {
  const { tags } = messageShape("This <strong>cannot</strong> be undone.");
  assert.deepEqual([...tags], ["strong"]);
});

test("identical messages have no problems, translated ones included", () => {
  assert.deepEqual(shapeProblems(APPS_COUNT, "{count, plural, =0 {Keine Apps} one {1 App} other {# Apps}}"), []);
  assert.deepEqual(
    shapeProblems(VERSIONS_ADDED, "{runtime} {versions} {count, plural, one {ist} other {sind}} jetzt verfügbar."),
    [],
  );
});

test("a language with different plural rules is not punished for them", () => {
  /*
   * Russian needs `few` and `many`; Japanese needs only `other`. Requiring
   * English's categories would be requiring English's grammar, and would make
   * every correct Russian plural a build failure.
   */
  assert.deepEqual(
    shapeProblems(APPS_COUNT, "{count, plural, =0 {Нет приложений} one {# приложение} few {# приложения} many {# приложений} other {# приложения}}"),
    [],
  );
  assert.deepEqual(shapeProblems(APPS_COUNT, "{count, plural, other {# 個のアプリ}}"), []);
});

test("a dropped placeholder is caught", () => {
  // The sentence renders with a hole where the version should be.
  const problems = shapeProblems("PHP {version} is required.", "PHP wird benötigt.");
  assert.deepEqual(problems, ["{version} is missing"]);
});

test("a translated placeholder NAME is caught", () => {
  /*
   * The commonest machine-translation failure, and the one that throws at
   * render rather than looking odd: the argument name is code, not prose.
   */
  const problems = shapeProblems("{count} sites", "{anzahl} Seiten");
  assert.deepEqual(problems.sort(), ["{anzahl} is not in the English string", "{count} is missing"]);
});

test("a plural flattened into a plain placeholder is caught", () => {
  const problems = shapeProblems(APPS_COUNT, "{count} Apps");
  assert.deepEqual(problems, ["{count} is a plain placeholder but English has plural"]);
});

test("a plural that lost its other branch is caught", () => {
  // ICU refuses to format it — every count that is not 1 throws.
  const problems = shapeProblems(APPS_COUNT, "{count, plural, =0 {Keine Apps} one {1 App}}");
  assert.ok(problems.some((p) => p.includes('has no "other" branch')), problems.join(" | "));
});

test("a lost or invented tag is caught", () => {
  assert.deepEqual(shapeProblems("<strong>Careful</strong>", "Vorsicht"), ["<strong> is missing"]);
  assert.deepEqual(shapeProblems("Careful", "<b>Vorsicht</b>"), ["<b> is not in the English string"]);
});

test("every string in every shipped locale matches English", () => {
  /*
   * The check itself, run over the real files — so this test fails for the same
   * reason the build gate does, rather than only proving the parser works on
   * examples somebody chose.
   */
  const dir = path.join(import.meta.dirname, "..", "messages");
  const en = JSON.parse(fs.readFileSync(path.join(dir, "en.json"), "utf8"));

  const flatten = (obj, prefix = "") =>
    Object.entries(obj).flatMap(([k, v]) =>
      v && typeof v === "object" ? flatten(v, `${prefix}${k}.`) : [[`${prefix}${k}`, v]],
    );
  const englishStrings = flatten(en);
  const at = (obj, key) =>
    key.split(".").reduce((o, part) => (o && typeof o === "object" ? o[part] : undefined), obj);

  const failures = [];
  for (const file of fs.readdirSync(dir).filter((f) => f.endsWith(".json") && f !== "en.json")) {
    const messages = JSON.parse(fs.readFileSync(path.join(dir, file), "utf8"));
    for (const [key, source] of englishStrings) {
      const translated = at(messages, key);
      if (typeof source !== "string" || typeof translated !== "string") continue;
      for (const problem of shapeProblems(source, translated)) {
        failures.push(`${file} ${key} — ${problem}`);
      }
    }
  }
  assert.deepEqual(failures, []);
});
