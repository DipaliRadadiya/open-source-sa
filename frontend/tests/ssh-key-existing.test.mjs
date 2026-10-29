import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

import { keyType } from "../lib/system-users/key-type.js";
import { sshKeySchema } from "../lib/schemas/system-user.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

// 2083540c (Dipali, 28 Oct): the same key again is 200 + existing, keys carry `type`, sk- keys are accepted.
test("adding a key that is already there says so instead of 'added'", () => {
  const dialog = read("components/system-users/ssh-keys-dialog.jsx");
  assert.match(dialog, /const \{ data \} = await addSystemUserSshKey\(user\.id, values\);/);
  assert.match(dialog, /if \(data\?\.existing\) toast\.info\(data\.message \?\? t\("toast\.keyExists"\)\);\s*else toast\.success\(t\("toast\.keyAdded"\)\);/);
});

test("the key type reads as people say it, and an unknown or missing one shows nothing", () => {
  assert.deepEqual(keyType("ssh-ed25519"), { algo: "Ed25519", securityKey: false });
  assert.deepEqual(keyType("ssh-rsa"), { algo: "RSA", securityKey: false });
  assert.deepEqual(keyType("ecdsa-sha2-nistp384"), { algo: "ECDSA", securityKey: false });
  assert.deepEqual(keyType("sk-ssh-ed25519@openssh.com"), { algo: "Ed25519", securityKey: true });
  assert.deepEqual(keyType("sk-ecdsa-sha2-nistp256@openssh.com"), { algo: "ECDSA", securityKey: true });
  for (const none of [undefined, null, "", "x-unknown"]) assert.equal(keyType(none), null);
});

test("hardware-token keys pass the form; garbage still does not", () => {
  const ok = (public_key) => sshKeySchema.safeParse({ name: "k", public_key }).success;
  assert.ok(ok("sk-ssh-ed25519@openssh.com AAAAGnNrLXNzaC1lZDI1NTE5QG9wZW5zc2guY29t user@yubikey"));
  assert.ok(ok("sk-ecdsa-sha2-nistp256@openssh.com AAAAInNrLWVjZHNh user@yubikey"));
  assert.ok(ok("ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAA me"));
  assert.ok(!ok("not a key"));
});

test("both new strings exist in all 8 languages", () => {
  for (const locale of LOCALES) {
    const su = JSON.parse(read(`messages/${locale}.json`)).systemUsers;
    assert.ok(su.toast.keyExists, `${locale}: keyExists`);
    assert.ok(su.sshKeys.securityKey.includes("{algo}"), `${locale}: securityKey`);
  }
});
