import { test } from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { SSH_RISKS, SEVERE_SSH_RISKS, isSevereSshChange } from "../lib/settings/ssh-risks.js";

const root = path.resolve(path.dirname(fileURLToPath(import.meta.url)), "..");

test("a port move on its own stays blue", () => {
  // The panel opens the new port in the firewall before applying, so this is
  // the one consequence that is genuinely handled for you. Reddening it would
  // make the colour meaningless on the changes that are not.
  assert.equal(isSevereSshChange(["port"]), false);
  assert.equal(isSevereSshChange([]), false);
  assert.equal(isSevereSshChange(), false);
});

test("anything that can cost you access goes red", () => {
  assert.equal(isSevereSshChange(["passwordOff"]), true);
  assert.equal(isSevereSshChange(["rootOff"]), true);
  assert.equal(isSevereSshChange(["rootPassword"]), true);
});

test("the worst item in the list wins", () => {
  // The dialog lists one to four consequences at once; a safe one must not
  // dilute a dangerous one.
  assert.equal(isSevereSshChange(["port", "passwordOff"]), true);
  assert.equal(isSevereSshChange(["port", "rootOff", "passwordOff"]), true);
});

test("every consequence the form can raise is classified", () => {
  /*
   * The keys are pushed inline in the component and the classification lives
   * here, with nothing linking them. Add a fifth consequence and it would
   * silently inherit the reassuring colour — on the one screen in the panel
   * that can lock you out of your own server.
   */
  const source = fs.readFileSync(
    path.join(root, "components", "settings", "ssh-form.jsx"),
    "utf8",
  );
  const pushed = [...source.matchAll(/risks\.push\("([^"]+)"\)/g)].map((m) => m[1]);

  assert.ok(pushed.length >= 4, `expected the form to raise consequences, found ${pushed.length}`);

  for (const risk of pushed) {
    assert.ok(
      SSH_RISKS.includes(risk),
      `ssh-form raises "${risk}" but ssh-risks.js has never heard of it`,
    );
  }
  for (const risk of SSH_RISKS) {
    assert.ok(
      pushed.includes(risk),
      `ssh-risks.js lists "${risk}" but the form no longer raises it`,
    );
  }
});

test("severe risks are a subset of the known risks", () => {
  for (const risk of SEVERE_SSH_RISKS) {
    assert.ok(SSH_RISKS.includes(risk), `"${risk}" is severe but not a known risk`);
  }
});

test("every consequence has wording in every locale", () => {
  const locales = fs
    .readdirSync(path.join(root, "messages"))
    .filter((f) => f.endsWith(".json"));

  for (const file of locales) {
    const confirm = JSON.parse(
      fs.readFileSync(path.join(root, "messages", file), "utf8"),
    ).settings?.security?.confirm;

    for (const risk of SSH_RISKS) {
      assert.equal(
        typeof confirm?.[risk],
        "string",
        `${file}: settings.security.confirm.${risk} missing`,
      );
    }
  }
});
