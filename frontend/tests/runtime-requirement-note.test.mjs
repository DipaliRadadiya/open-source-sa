import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { rangeLabel } from "../lib/runtime/version-range.js";

const form = fs.readFileSync("components/applications/create-application-form.jsx", "utf8");

/*
 * Asked for as a note about the SERVER DEFAULT PHP version — "Server default
 * PHP 8.2 or higher is required for this application."
 *
 * That rule does not exist in this panel. `AbstractPhpInstaller::phpCommand`
 * runs the install on `$application->php_version` and only falls back to
 * `server.default_php_version` when the site names none, so the server default
 * constrains nothing about an application that has chosen a version.
 *
 * The real, hidden requirement is the application's own supported range. The
 * dropdown was already filtered to it and said nothing, so on a server with PHP
 * 8.1 and 8.3 an app needing 8.2+ silently offered one option. The note states
 * that requirement instead.
 */

test("the note states the application's own requirement", () => {
  assert.match(form, /const runtimeRequirement = isRuntime \? rangeLabel\(runtimeRange\)/);
  assert.match(form, /t\("form\.runtimeRequirement"/);

  const en = JSON.parse(fs.readFileSync("messages/en.json", "utf8"));
  const copy = en.applications.form.runtimeRequirement;

  assert.match(copy, /\{runtime\}/, "the note must name PHP or Node, not assume one");
  assert.match(copy, /\{range\}/);
  assert.doesNotMatch(
    copy,
    /server default/i,
    "the note claims a server-default rule this panel does not have",
  );
});

test("it only appears when the application actually has a limit", () => {
  /*
   * An app that runs on anything would otherwise get "needs PHP" with no
   * version in it — noise on every form that has no requirement to state.
   * Driven in a browser too: an unbounded type renders no note.
   */
  assert.equal(rangeLabel({ min: null, max: null }), "");
  assert.equal(rangeLabel(null), "");

  // The branch is truthiness on the label, so "" renders nothing.
  assert.match(form, /\) : runtimeRequirement \? \(/);
});

test("every range shape the API can send reads as a sentence", () => {
  assert.equal(rangeLabel({ min: "8.2", max: null }), "8.2+");
  assert.equal(rangeLabel({ min: "8.1", max: "8.3" }), "8.1 – 8.3");
  assert.equal(rangeLabel({ min: null, max: "8.2" }), "≤ 8.2");
});

test("Node fields get their own range, not PHP's", () => {
  /*
   * One component renders both runtimes. Reading phpRange for a Node field
   * would tell a NodeBB install it needs a PHP version.
   */
  assert.match(form, /config\.source === "php_versions"\s*\?\s*phpRange/);
  assert.match(form, /config\.source === "node_versions"\s*\?\s*nodeRange/);
});

test("the note is translated everywhere", () => {
  const locales = fs
    .readFileSync("i18n/routing.js", "utf8")
    .match(/export const locales = \[([^\]]+)\]/)[1]
    .split(",")
    .map((code) => code.trim().replace(/['"]/g, ""))
    .filter(Boolean);

  const english = JSON.parse(fs.readFileSync("messages/en.json", "utf8"))
    .applications.form.runtimeRequirement;

  for (const locale of locales) {
    const copy = JSON.parse(fs.readFileSync(`messages/${locale}.json`, "utf8"))
      .applications.form.runtimeRequirement;
    assert.ok(copy, `${locale} is missing form.runtimeRequirement`);
    assert.match(copy, /\{runtime\}/, `${locale} dropped the {runtime} placeholder`);
    assert.match(copy, /\{range\}/, `${locale} dropped the {range} placeholder`);
    if (locale !== "en") {
      assert.notEqual(copy, english, `${locale} still carries the English sentence`);
    }
  }
});
