import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");

const hint = fs.readFileSync(
  path.join(root, "components/ui/shortcut-hint.jsx"),
  "utf8",
);

/** Every place a Cmd/Ctrl chord is bound. */
const HANDLERS = [
  "components/ui/sidebar.jsx",
  "components/applications/environment/environment-editor.jsx",
  "components/applications/files/file-editor-dialog.jsx",
];

test("every shortcut accepts both the Mac and the PC modifier", () => {
  // A handler that tests only ctrlKey is dead on a Mac, and one that tests
  // only metaKey is dead everywhere else. Both, always.
  for (const file of HANDLERS) {
    const source = fs.readFileSync(path.join(root, file), "utf8");

    assert.match(
      source,
      /metaKey \|\| \w*\.?ctrlKey|ctrlKey \|\| \w*\.?metaKey/,
      `${file} must accept Cmd and Ctrl alike`,
    );
  }
});

test("the hint shows the key the platform actually has", () => {
  assert.match(hint, /userAgentData\?\.platform \?\? navigator\.platform/);
  assert.match(hint, /mac \? "⌘" : "Ctrl"/);
});

test("the platform is read through a store with a server snapshot", () => {
  // Not an effect. The server has no platform, so an effect would paint "Ctrl"
  // and then correct it to "⌘" on a Mac — a visible flicker on every load, and
  // the cascading-render pattern the lint rules refuse.
  assert.match(hint, /useSyncExternalStore/);
  assert.match(hint, /const notMac = \(\) => false/);
});

test("the hint is decorative, so a screen reader is not read punctuation", () => {
  // The control it sits beside already names itself.
  assert.match(hint, /aria-hidden="true"/);
});

test("the hint carries the slot the tooltip surface styles", () => {
  // TooltipContent reserves padding for `data-slot=kbd` and lifts it above the
  // arrow. Without the attribute the chip sits wrong inside a tooltip.
  assert.match(hint, /data-slot="kbd"/);

  const tooltip = fs.readFileSync(path.join(root, "components/ui/tooltip.jsx"), "utf8");
  assert.match(tooltip, /has-data-\[slot=kbd\]/);
});

test("the sidebar shortcut is finally documented where the control is", () => {
  // Cmd/Ctrl+B has toggled the sidebar since it was added and nothing said so.
  const toggle = fs.readFileSync(
    path.join(root, "components/sections/sidebar-toggle.jsx"),
    "utf8",
  );

  assert.match(toggle, /<ShortcutHint letter="B"/);

  const sidebar = fs.readFileSync(path.join(root, "components/ui/sidebar.jsx"), "utf8");
  const bound = sidebar.match(/SIDEBAR_KEYBOARD_SHORTCUT = "(\w)"/);

  assert.ok(bound, "the sidebar must declare its shortcut key");
  assert.equal(
    bound[1].toUpperCase(),
    "B",
    "the hint must name the key that is actually bound",
  );
});
