import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

const read = (p) => fs.readFileSync(p, "utf8");

test("the restore banner's Hide is a real button, and Undo and Dismiss match", () => {
  const src = read("components/backups/restore-progress.jsx");
  // Ghost text read as a label, not an action.
  assert.doesNotMatch(src, /variant="ghost"[^>]*onClick=\{onDismiss\}/);
  assert.equal((src.match(/<EyeOff className="size-4" \/>\s*\{t\("hide"\)\}/g) ?? []).length, 2);
  // The banner's action is the ordinary filled button; closing it is neutral —
  // white, no outline, no status colour (Krishna, 12:41 and 12:44).
  assert.doesNotMatch(src, /variant="outline" size="sm"/);
  assert.match(src, /<Button size="sm" onClick=\{openUndo\} disabled=\{loadingUndo\}>/);
  assert.match(src, /onClick=\{onDismiss\} className=\{NEUTRAL\}>\s*<X className="size-4" \/>/);
  assert.match(src, /const NEUTRAL =\s*"border-transparent bg-background text-foreground/);
  assert.doesNotMatch(src, /ON_TINT|text-success\]|\[&_svg\]:text-/);
});

test("a hint icon no longer makes its label taller than a plain one", () => {
  assert.match(read("components/ui/label.jsx"), /className="-my-\[3px\]"/);
});

test("the password field's focus ring has room at the bottom of its section", () => {
  assert.match(read("components/applications/security/security-section.jsx"), /-mx-1 -mb-1 overflow-hidden px-1 pb-1/);
});

test("a worker that will not start is explained on the form, not in a vanishing toast", () => {
  // Create: a bare 500 means the worker never existed — the message names the
  // command to check, and the form stays with its values.
  const create = read("components/applications/workers/create-worker-dialog.jsx");
  assert.match(create, /\(error\.response\?\.status \?\? 0\) >= 500/);
  assert.match(create, /form\.setError\("root\.server", \{ message: apiMessage\(error, t\("create\.failed"\)\) \}\)/);
  // Edit: the API saved the row and then could not start it, so the worker is
  // now stopped — a different sentence, and the list is re-read behind it.
  const edit = read("components/applications/workers/edit-worker-dialog.jsx");
  assert.match(edit, /\(error\.response\?\.status \?\? 0\) >= 500/);
  assert.match(edit, /form\.setError\("root\.server", \{ message: t\("edit\.failedStopped"\) \}\);\s*refresh\(\);/);
  for (const locale of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const w = JSON.parse(read(`messages/${locale}.json`)).applications.workers;
    assert.ok(w.create.failed && w.edit.failedStopped, locale);
  }
});

test("a save that changed no variable says so once, with nothing to expand", () => {
  const card = read("components/applications/environment/environment-history-card.jsx");
  assert.match(card, /const noKeys = !restored && keys\.length === 0/);
  assert.match(card, /const canShowValues = canManage && blocked !== "pruned" && !noKeys/);
  assert.match(card, /t\("actionChanged", \{ count: keys\.length \}\)/);
});

test("the diff stacks when the card is too narrow for three columns", () => {
  const diff = read("components/applications/environment/environment-diff.jsx");
  assert.match(diff, /className="@container"/);
  assert.match(diff, /hidden w-full text-xs @md:table/);
  assert.match(diff, /divide-y text-xs @md:hidden/);
  for (const locale of ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"]) {
    const s = JSON.parse(read(`messages/${locale}.json`)).applications.environment.history.status;
    assert.ok(s.added && s.removed && s.changed, locale);
  }
});
