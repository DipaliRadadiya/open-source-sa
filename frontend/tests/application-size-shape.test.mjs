import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { applicationSchema } from "../lib/schemas/application.js";

const BASE = {
  id: 1,
  name: "Site",
  domain: "example.com",
  site_type: "wordpress",
  status: "active",
};

test("keeps the directory size through parsing", () => {
  // `applicationSchema` does not passthrough, so a field the API sends and the
  // schema does not declare is dropped in silence. That is not hypothetical:
  // the Size column read "Not measured" on every row of a server whose sizes
  // were all measured and stored, because the number never survived here.
  const parsed = applicationSchema.parse({
    ...BASE,
    directory_size_bytes: 1048576,
    directory_size_measured_at_human: "2 hours ago",
  });

  assert.equal(parsed.directory_size_bytes, 1048576);
  assert.equal(parsed.directory_size_measured_at_human, "2 hours ago");
});

test("a never-measured site parses as null rather than failing", () => {
  const parsed = applicationSchema.parse({ ...BASE, directory_size_bytes: null });

  assert.equal(parsed.directory_size_bytes, null);
});

test("every field the sites table renders is declared in the schema", () => {
  // The general form of the bug, so the next column added to the table cannot
  // repeat it. Reads the table source for `row.original.<field>` and asserts
  // the schema declares each one — an undeclared field is dropped in silence.
  const table = fs.readFileSync(
    path.join(import.meta.dirname, "../components/applications/applications-table.jsx"),
    "utf8",
  );

  const used = [...new Set([...table.matchAll(/row\.original\.([a-z_]+)/g)].map((m) => m[1]))];
  const declared = Object.keys(applicationSchema.shape);
  const dropped = used.filter((field) => !declared.includes(field));

  assert.deepEqual(dropped, [], `dropped by the schema: ${dropped.join(", ")}`);
});
