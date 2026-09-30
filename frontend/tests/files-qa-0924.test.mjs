import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { parseSort, serializeSort, resolveShowHidden } from "../lib/files/view-prefs.js";
import { apiMessage } from "../lib/api/error-message.js";
import { rateLimitedMessage, setRateLimitedMessage } from "../lib/api/generic-error.js";
import { compressSuggestion } from "../lib/files/path-helpers.js";

const read = (p) => fs.readFileSync(p, "utf8");

test("a finished archive job from before this visit is not announced again", () => {
  // Five "is ready" toasts on one page load, on a real server.
  const src = read("components/applications/files/archive-jobs-banner.jsx");
  assert.match(src, /if \(!primed\.current\) \{/);
  assert.match(src, /if \(!ARCHIVE_IN_FLIGHT\.includes\(job\.status\)\) announced\.current\.add\(job\.id\)/);
});

test("Laravel's own 'Server Error' is not shown as a reason", () => {
  const err = { response: { status: 500, data: { message: "Server Error" } } };
  assert.equal(apiMessage(err, "Couldn't create the folder."), "Couldn't create the folder.");
  const real = { response: { status: 500, data: { message: "Could not write to the disk.", reference: "r1" } } };
  assert.equal(apiMessage(real, "x"), "Could not write to the disk. · r1");
});

test("a throttled request says so in the reader's language", () => {
  setRateLimitedMessage("Zu viele Anfragen.");
  const err = { response: { status: 429, data: { message: "Too Many Attempts." } } };
  assert.equal(apiMessage(err, "fallback"), "Zu viele Anfragen.");
  assert.equal(rateLimitedMessage(), "Zu viele Anfragen.");
  // A 429 that carries its own sentence keeps it.
  const own = { response: { status: 429, data: { message: "Wait 30 seconds before the next upload." } } };
  assert.equal(apiMessage(own, "fallback"), "Wait 30 seconds before the next upload.");
});

test("sort and hidden files are remembered", () => {
  assert.deepEqual(parseSort("size:desc"), [{ id: "size", desc: true }]);
  assert.deepEqual(parseSort("bogus:asc"), [{ id: "name", desc: false }]);
  assert.deepEqual(parseSort(undefined), [{ id: "name", desc: false }]);
  assert.equal(serializeSort([{ id: "modified", desc: false }]), "modified:asc");
  assert.equal(resolveShowHidden(undefined, "hide"), false, "remembered across folders");
  assert.equal(resolveShowHidden("1", "hide"), true, "an explicit URL wins");
  assert.equal(resolveShowHidden(undefined, undefined), true);
  const page = read("app/(app)/applications/[application]/files/page.jsx");
  assert.match(page, /resolveShowHidden\(rawHidden, cookieStore\.get\(HIDDEN_COOKIE\)\?\.value\)/);
  assert.match(read("components/applications/files/files-table.jsx"), /onSortingChange=\{\(sorting\) => writePref\(SORT_COOKIE, serializeSort\(sorting\)\)\}/);
});

test("dialogs close after the list has refreshed, not before", () => {
  assert.match(read("hooks/use-refresh.js"), /refreshThen: \(fn\) => \{/);
  for (const f of ["target-path-dialog", "new-folder-dialog", "new-file-dialog", "delete-file-dialog", "permissions-dialog", "bulk-dialogs", "fix-permissions-button", "upload-dialog", "trash-panel"]) {
    const src = read(`components/applications/files/${f}.jsx`);
    assert.match(src, /refreshThen/, f);
    assert.doesNotMatch(src, /router\.refresh\(\)/, `${f} still refreshes behind a closed dialog`);
  }
});

test("Upload is off when nothing chosen can be sent", () => {
  assert.match(read("components/applications/files/upload-dialog.jsx"), /!i\.nameTaken && !i\.spaceBlocked && \(i\.status === "pending"/);
});

test("date and permission cells are not tab stops", () => {
  const src = read("components/applications/files/files-table.jsx");
  assert.doesNotMatch(src, /<span tabIndex=\{0\} className="text-muted-foreground/);
  assert.match(src, /<span className="sr-only"> \(\{exact\}\)<\/span>/);
});

test("switching format re-suggests an untouched archive name", () => {
  const taken = new Set(["src.zip"]);
  assert.equal(compressSuggestion("src", ".zip", taken), "src-2.zip");
  assert.equal(compressSuggestion("src", ".tar.gz", taken), "src.tar.gz");
  assert.match(read("components/applications/files/archive-format-field.jsx"), /ARCHIVE_FORMATS\.some\(\(ext\) => value === suggest\(ext\)\)/);
});

test("a file too big for the disk does not block the smaller ones after it", () => {
  const src = read("components/applications/files/upload-dialog.jsx");
  assert.match(src, /if \(queued \+ item\.file\.size > usable\) \{/);
  assert.ok(src.indexOf("queued += item.file.size;") > src.indexOf("if (queued + item.file.size > usable)"), "count only what is sent");
});
