import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");

function dnsNote(source, key) {
  const markerIndex = source.indexOf(`t("${key}")`);
  assert.notEqual(markerIndex, -1, `${key} must exist`);

  // The note's own box (the muted one), not the inner column the sentence sits in.
  const start = source.lastIndexOf("<div className=", source.lastIndexOf("bg-muted/50", markerIndex));
  assert.notEqual(start, -1, `${key} must live in a note container`);

  return source.slice(start, markerIndex);
}

// 27 Aug: centred, when the sentence and IP shared one row. 9 Oct (Krishna): the row
// wrapped and the centred icon floated between two lines; now sentence, then IP row,
// with the icon on the first line.
test("domain DNS note icons sit on the first line, with the IP on its own row", () => {
  const cases = [
    ["components/applications/create-application-form.jsx", "form.dnsNote"],
    ["components/applications/domains/add-domain-dialog.jsx", "add.dnsNoteIp"],
  ];

  for (const [file, key] of cases) {
    const source = fs.readFileSync(path.join(root, file), "utf8");
    const note = dnsNote(source, key);

    assert.match(note, /className="flex items-start gap-2\.5 rounded-lg/);
    assert.match(note, /<Info className="mt-px size-3\.5 shrink-0"/);
    assert.match(note, /<div className="min-w-0 space-y-1\.5">\s*<p>\{$/);
  }
});
