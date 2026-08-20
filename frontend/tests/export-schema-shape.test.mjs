import { test } from "node:test";
import assert from "node:assert/strict";
import { exportsResponseSchema } from "../lib/schemas/database.js";

const ROW = {
  id: 1,
  database_id: 52,
  database: "shop",
  engine: "mysql",
  file: "shop-mysql-20260820-082444-diwlgp.sql",
  status: "completed",
  size_bytes: 94995,
  size_human: "93 KB",
  reason: null,
  message: null,
  reference: null,
  available: true,
  download_url: "https://panel.example/api/databases/exports/shop.sql",
  created_at: "20-08-2026 08:24:41",
  finished_at: "20-08-2026 08:24:45",
};

test("parses a row started by a signed-in user", () => {
  // The list eager-loads the user, so `requested_by` is an object. Declared as
  // a string, this failed — and because `z.array()` fails whole, one such row
  // made the entire response unparseable: the poll discarded every answer in
  // silence and the server render fell back to an empty list.
  const parsed = exportsResponseSchema.safeParse({
    exports: [{ ...ROW, requested_by: { id: 6, username: "suresh" } }],
  });

  assert.ok(parsed.success, JSON.stringify(parsed.error?.issues));
  assert.equal(parsed.data.exports[0].download_url, ROW.download_url);
});

test("still parses a row nobody is attached to", () => {
  // Scheduled and system-initiated exports have no user; the resource sends
  // null, and an export list that dropped those would be its own bug.
  const parsed = exportsResponseSchema.safeParse({
    exports: [{ ...ROW, requested_by: null }, { ...ROW, id: 2 }],
  });

  assert.ok(parsed.success, JSON.stringify(parsed.error?.issues));
  assert.equal(parsed.data.exports.length, 2);
});
