import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const progress = read("components/databases/database-install-progress.jsx");
const confirm = read("components/databases/install-confirm.jsx");
const engineBar = read("components/databases/engine-bar.jsx");
const engineState = read("components/databases/engine-state.jsx");
const setup = read("components/setup/setup-component.jsx");

const { locales } = await import("../i18n/routing.js");

test("the progress card names the engine, not just the step", () => {
  // The server owns the step wording and none of it names an engine —
  // "Downloading packages" is true of MySQL, MariaDB, PostgreSQL, MongoDB and
  // Redis alike. Installing a second engine is where that stops being usable.
  assert.match(progress, /t\("titleWithEngine", \{ name: label, step \}\)/);
  assert.match(progress, /const title = label \?/);
});

test("the name is in the aria-live line, not beside it", () => {
  // A screen reader announcing "Configuring packages" every few seconds with no
  // subject has the same problem the visible text had.
  const region = progress.slice(
    progress.indexOf('aria-live="polite"'),
    progress.indexOf("{progress.started_at_human"),
  );

  assert.ok(region.length > 0, "the aria-live region must be findable");
  assert.match(region, /\{title\}/);
});

test("every caller still supplies a name to show", () => {
  // The prop existed and was spent only on the progress bar's aria-label, so
  // this has always been available — it was simply never rendered.
  assert.match(engineBar, /label=\{t\(`engines\.\$\{progressEngine\.engine\}`\)\}/);
  assert.match(engineState, /label=\{name\}/);
  assert.match(setup, /label=\{component\.title\}/);
});

test("the toast names the engine too", () => {
  // It is the only thing on screen for the second or two before the progress
  // card appears, and "Installing." does not answer "which?".
  assert.match(confirm, /const name = t\(`engines\.\$\{engine\.engine\}`\)/);
  assert.match(confirm, /t\("install\.already", \{ name \}\)/);
  assert.match(confirm, /t\("install\.queued", \{ name \}\)/);
});

test("the strings carry their placeholders in every locale", () => {
  for (const locale of locales) {
    const m = JSON.parse(read(`messages/${locale}.json`));

    const title = m.databaseInstallProgress?.titleWithEngine;
    assert.equal(typeof title, "string", `${locale}: titleWithEngine must exist`);
    // A dropped placeholder still reads as a sentence — it just silently stops
    // naming the thing it exists to name.
    assert.ok(title.includes("{name}"), `${locale}: titleWithEngine must keep {name}`);
    assert.ok(title.includes("{step}"), `${locale}: titleWithEngine must keep {step}`);

    for (const key of ["queued", "already"]) {
      const value = m.databases?.install?.[key];
      assert.equal(typeof value, "string", `${locale}: install.${key} must exist`);
      assert.ok(value.includes("{name}"), `${locale}: install.${key} must keep {name}`);
    }
  }
});
