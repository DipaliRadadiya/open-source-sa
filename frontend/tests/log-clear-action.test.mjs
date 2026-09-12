import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const toolbar = read("components/logs/log-toolbar.jsx");
const panel = read("components/applications/logs/application-logs-panel.jsx");

test("the clear action is not inside the view-action group", () => {
  // Those are view actions — they change what you see and nothing else.
  // Clearing destroys the thing being viewed, and one pixel from Reload is how
  // a misclick becomes unrecoverable.
  const group = toolbar.slice(toolbar.indexOf("One segmented group"));
  const segmented = group.slice(0, group.indexOf("</div>"));

  assert.ok(
    !/onClear/.test(segmented),
    "the clear action must not be a segment of the view-action group",
  );
  assert.match(toolbar, /onClear \? \(/, "and it must still be rendered");
});

test("no clear action without the grant, hidden rather than disabled", () => {
  // An always-disabled destructive control invites "why not", and the answer is
  // "you may not" — which is better said by its absence.
  assert.match(
    panel,
    /onClear=\{canManage \? \(\) => setConfirmClear\(true\) : null\}/,
    "the action must be null for a viewer, not merely disabled",
  );
  assert.match(
    toolbar,
    /onClear = null/,
    "and the toolbar must default to absent",
  );
});

test("clearing is confirmed, and the dialog names the log", () => {
  // "Clear log?" beside a tab strip does not say which one, and this cannot be
  // undone.
  const dialog = panel.slice(panel.indexOf("<ConfirmDialog"));

  assert.match(dialog, /tone="destructive"/);
  assert.match(
    dialog,
    /clear\.title", \{ label:/,
    "the title must name the source",
  );
  assert.match(dialog, /onConfirm=\{clearLog\}/);
});

test("the emptied log is shown emptied, not re-read", () => {
  // A re-read of a just-truncated busy access log returns the requests that
  // arrived in between, which reads as the clear having failed.
  const handler = panel.slice(
    panel.indexOf("const clearLog"),
    panel.indexOf("const copy"),
  );

  assert.match(handler, /setLines\(\[\]\)/);
  assert.ok(
    !/readApplicationLog|load\(/.test(handler),
    "clearing must not refetch the log it just emptied",
  );
});

const serverPanel = read("components/logs/logs-panel.jsx");
const schema = read("lib/schemas/log.js");

test("the source schema names clearable, or the action disappears on poll", () => {
  // Zod strips unknown keys. Without this the Clear action rendered from the
  // server payload and then vanished the moment the source poll replaced the
  // catalog — a button that disappears while you look at it.
  assert.match(schema, /clearable: z\.boolean\(\)/);
  // False by default: a server too old to send the field has no DELETE route
  // either, so offering the action there would fail at the click.
  assert.match(
    schema,
    /clearable: z\.boolean\(\)\.optional\(\)\.default\(false\)/,
  );
});

test("server logs offer the action only where the API says it is allowed", () => {
  // Never inferred from the key: the registry decides, and a second client must
  // not be able to offer what the server will refuse.
  assert.match(
    serverPanel,
    /canManage && source\?\.clearable \? \(\) => setConfirmClear\(true\) : null/,
  );
});

test("clearing a server log resets the incremental cursor", () => {
  // It counts bytes into a file that is now zero bytes long. Left alone, the
  // next poll asks for a range past the end and renders nothing for as long as
  // the page stays open.
  const handler = serverPanel.slice(
    serverPanel.indexOf("const clearSelected"),
    serverPanel.indexOf("const copy"),
  );

  assert.match(handler, /cursor\.current = 0/);
  assert.match(handler, /setLines\(\[\]\)/);
});
