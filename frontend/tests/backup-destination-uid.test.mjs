import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported as "copy for destination is not working".
 *
 * It worked. Driven on the live panel the clipboard received the uid
 * (fd3596b9-5e0a-4a58-8b5a-b6aa9fdd53c2) and the success tick rendered for
 * 1.4s of its 1.5s window. Nothing was broken mechanically.
 *
 * What was broken was the promise. The button sat inline immediately after
 * "Cloudflare" with nothing between them and nothing to show what it would
 * copy, so it reads as "copy the destination" — and hands you a 36-character
 * UUID instead. A correct action that returns something you did not ask for is
 * indistinguishable from a broken one, and the only thing naming it was an
 * aria-label you have to hover to read.
 *
 * The uid now sits on its own line showing its first block, so the icon is
 * beside the thing it copies.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const table = read("components/backups/backups-history-table.jsx");
const code = table
  .replace(/\/\*[\s\S]*?\*\//g, "")
  .replace(/\{\/\*[\s\S]*?\*\/\}/g, "");

test("the uid is shown, not just copied", () => {
  // You cannot tell what an icon will give you from the icon.
  assert.match(code, /\{uidFragment\(uid\)\}/);
  assert.match(code, /export function uidFragment|function uidFragment\(uid\)/);
});

test("the copy button sits next to the uid, not next to the bucket name", () => {
  // The name and the uid are now separate rows; the button is inside the uid's.
  assert.match(code, /flex min-w-0 flex-col gap-0\.5/);
  const uidRow = code.slice(code.indexOf("{uid && backupHasArchive"));
  const copyAt = uidRow.indexOf("<CopyButton");
  const fragmentAt = uidRow.indexOf("uidFragment");
  assert.ok(fragmentAt !== -1 && copyAt !== -1, "both should be in the uid row");
  assert.ok(fragmentAt < copyAt, "the value it copies should precede the button");
});

test("only a fragment is rendered, because this column has no fixed width", () => {
  /*
   * 36 mono characters is what gave this table a 1244px floor and overflowed
   * its container by 270px at 1024 the last time something long went in the
   * destination column. The clipboard still gets the whole thing.
   */
  assert.match(code, /text\.slice\(0, 8\)/);
  assert.match(code, /<CopyButton value=\{uid\}/);
  assert.match(code, /title=\{uid\}/);
});

test("it still only appears when an archive exists", () => {
  // The uid is stamped at creation, before any upload, so a failed run carries
  // one that names nothing in the bucket.
  assert.match(code, /\{uid && backupHasArchive\(row\.original\.status\)/);
});

test("uidFragment leaves a short value alone and never lies about length", () => {
  const src = read("components/backups/backups-history-table.jsx");
  const body = src.slice(src.indexOf("function uidFragment"));
  assert.match(body, /text\.length > 8 \? `\$\{text\.slice\(0, 8\)\}…` : text/);
});
