import test from "node:test";
import assert from "node:assert/strict";
import { canOpenFile } from "../lib/files/openable.js";

test("archives are not openable", () => {
  for (const name of [
    "site.tar",
    "backup.tar.gz",
    "backup.tgz",
    "files.zip",
    "dump.sql.gz",
    "old.7z",
  ]) {
    assert.equal(canOpenFile(name), false, name);
  }
});

test("binaries, media and container documents are not openable", () => {
  for (const name of ["invoice.pdf", "clip.mp4", "font.woff2", "app.so", "db.sqlite"]) {
    assert.equal(canOpenFile(name), false, name);
  }
});

test("text and images stay openable", () => {
  for (const name of [
    "index.php",
    "wp-config.php",
    ".env",
    "README",
    "composer.lock",
    "error.log",
    "logo.png",
    "icon.svg",
    "dump.sql",
  ]) {
    assert.equal(canOpenFile(name), true, name);
  }
});

test("the check ignores case", () => {
  assert.equal(canOpenFile("BACKUP.TAR.GZ"), false);
  assert.equal(canOpenFile("Notes.TXT"), true);
});
