import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");

function dnsNote(source, key) {
  const markerIndex = source.indexOf(`t("${key}")`);
  assert.notEqual(markerIndex, -1, `${key} must exist`);

  const start = source.lastIndexOf("<div className=", markerIndex);
  assert.notEqual(start, -1, `${key} must live in a note container`);

  return source.slice(start, markerIndex);
}

test("domain DNS note icons stay centered with their complete rows", () => {
  const cases = [
    ["components/applications/create-application-form.jsx", "form.dnsNote"],
    ["components/applications/domains/add-domain-dialog.jsx", "add.dnsNoteIp"],
  ];

  for (const [file, key] of cases) {
    const source = fs.readFileSync(path.join(root, file), "utf8");
    const note = dnsNote(source, key);

    assert.match(note, /className="flex items-center gap-2 rounded-lg/);
    assert.match(note, /<Info className="size-3\.5 shrink-0"/);
    assert.doesNotMatch(note, /items-start/);
    assert.doesNotMatch(note, /mt-0\.5/);
  }
});
