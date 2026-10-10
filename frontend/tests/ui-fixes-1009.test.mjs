import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// Krishna, 9 Oct: two layout faults from screenshots.
const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");

test("the connection string breaks between its parts, not inside a name", () => {
  const src = read("components/databases/connection-string.jsx");
  // Before the user, the host and the database: "db_without_ap" / "p" was the bug.
  assert.equal(src.match(/<wbr \/>/g)?.length, 3);
});

test("the add-domain DNS note puts its icon on the first line, not centred on the block", () => {
  const src = read("components/applications/domains/add-domain-dialog.jsx");
  assert.match(src, /<div className="flex items-start gap-2\.5 rounded-lg bg-muted\/50 p-3 text-xs text-muted-foreground">\s*<Info className="mt-px size-3\.5 shrink-0" aria-hidden \/>/);
});
