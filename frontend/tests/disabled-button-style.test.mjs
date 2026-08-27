import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");

function read(relativePath) {
  return fs.readFileSync(path.join(root, relativePath), "utf8");
}

function jsxFiles(directory) {
  return fs.readdirSync(directory, { withFileTypes: true }).flatMap((entry) => {
    const fullPath = path.join(directory, entry.name);
    if (entry.isDirectory()) return jsxFiles(fullPath);
    return entry.isFile() && entry.name.endsWith(".jsx") ? [fullPath] : [];
  });
}

test("the shared Save footer retains its primary variant when disabled", () => {
  const source = read("components/ui/card-save-footer.jsx");
  const start = source.indexOf('<Button\n            type={submit ? "submit" : "button"}');
  const end = source.indexOf("</Button>", start);

  assert.notEqual(start, -1, "shared Save button must exist");
  assert.notEqual(end, -1, "shared Save button must close");
  assert.doesNotMatch(source, /quietWhenClean/);
  assert.doesNotMatch(source.slice(start, end), /variant=/);
});

test("blocked Restore actions retain their destructive semantics", () => {
  for (const file of [
    "components/backups/backups-history-table.jsx",
    "components/backups/backups-cards.jsx",
  ]) {
    const source = read(file);
    assert.match(
      source,
      /variant="destructive"[\s\S]{0,160}disabled=\{Boolean\(blocker\)\}/,
      file,
    );
    assert.doesNotMatch(source, /variant=\{blocker\s*\?/);
  }
});

test("disabled controls do not switch variants because they are unavailable", () => {
  const forbidden = /variant=\{[^}]*\b(?:dirty|isDirty|saveReason|blocker|quietWhenClean)\b[^}]*\}/;
  const failures = [];

  for (const directory of ["app", "components"]) {
    for (const file of jsxFiles(path.join(root, directory))) {
      const source = fs.readFileSync(file, "utf8");
      if (forbidden.test(source)) failures.push(path.relative(root, file));
    }
  }

  assert.deepEqual(failures, []);
});
