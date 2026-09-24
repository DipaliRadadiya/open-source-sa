import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { execSync } from "node:child_process";

const read = (p) => fs.readFileSync(p, "utf8");

test("E4: the .env editor registers with the panel's unsaved-changes guard", () => {
  const editor = read("components/applications/environment/environment-editor.jsx");
  assert.match(editor, /useWatchUnsaved\("environment-editor", dirty\)/);
  // Its own beforeunload covered reload/close only; the guard covers both.
  assert.doesNotMatch(editor, /addEventListener\("beforeunload"/);
});

test("W1: worker dialogs close only after the list has re-read", () => {
  for (const f of ["delete-worker-dialog", "create-worker-dialog", "edit-worker-dialog"]) {
    const src = read(`components/applications/workers/${f}.jsx`);
    assert.match(src, /refreshThen\(\(\) => \{/, f);
    assert.doesNotMatch(src, /router\.refresh\(\)/, f);
  }
  assert.match(read("components/applications/workers/delete-worker-dialog.jsx"), /pending=\{pending \|\| refreshing\}/);
});

test("W2: the command placeholder is this application's own preset, never a fixed Laravel line", () => {
  const src = read("components/applications/workers/worker-command-field.jsx");
  assert.doesNotMatch(src, /artisan queue:work/);
  assert.match(src, /presets\.find\(\(p\) => p\.command\)\?\.command \?\? t\("form\.commandPlaceholder"\)/);
  for (const l of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    assert.ok(JSON.parse(read(`messages/${l}.json`)).applications.workers.form.commandPlaceholder, l);
  }
});

test("W3: no panel form lets the browser's English bubble answer first", () => {
  const files = execSync("grep -rln '<form' components app --include=*.jsx", { encoding: "utf8" }).trim().split("\n");
  for (const f of files) {
    const src = read(f);
    // Real tags start a line; a comment mentioning <form> does not. A tag's
    // attributes can hold `=>`, so look at the opening stretch, not up to ">".
    for (const m of src.matchAll(/^\s*<form\b/gm)) {
      const opening = src.slice(m.index, m.index + 400);
      assert.match(opening, /noValidate/, `${f}: ${opening.slice(0, 60)}`);
    }
  }
});

test("E5: the restore picker is a radio group led by the saved time", () => {
  const src = read("components/applications/environment/restore-backup-dialog.jsx");
  assert.match(src, /role="radiogroup"/);
  assert.match(src, /role="radio"\s+aria-checked=\{active\}/);
  assert.match(src, /parseApiWallClock\(backup\.created_at\)/);
});
