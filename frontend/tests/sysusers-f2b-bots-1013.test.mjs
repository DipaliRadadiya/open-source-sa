import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { effectiveBlockedBots, isAllowedBot } from "../lib/schemas/bot-rule.js";

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = ["en", "es", "de", "fr", "pt", "ja", "ru", "hi"];

test("system user passwords: copy, never an eye", () => {
  const reveal = read("components/system-users/password-reveal.jsx");
  assert.doesNotMatch(reveal, /Eye/);
  assert.match(reveal, /<CopyButton value=\{password\}/);
  for (const file of ["components/system-users/create-system-user-dialog.jsx", "components/system-users/system-user-password-dialog.jsx"]) {
    const src = read(file);
    assert.doesNotMatch(src, /<PasswordInput/, file);
    assert.match(src, /<CopyablePasswordInput/, file);
  }
  // Masked: a password on screen can be read over a shoulder (Krishna, 13 Oct).
  assert.match(read("components/system-users/copyable-password-input.jsx"), /type="password"/);
});

test("system user home path has a copy button in the table and the cards", () => {
  for (const file of ["components/system-users/system-users-table.jsx", "components/system-users/system-users-cards.jsx"]) {
    assert.match(read(file), /<CopyButton value=\{(row\.original|user)\.home_path\} label=\{t\("detail\.copyPath"\)\}/, file);
  }
});

test("Fail2ban explains SSH and Repeat offenders in every locale", () => {
  assert.match(read("components/fail2ban/jails-card.jsx"), /t\.has\(`jails\.about\.\$\{jail\.name\}`\)/);
  for (const locale of LOCALES) {
    const about = JSON.parse(read(`messages/${locale}.json`)).fail2ban.jails.about;
    assert.ok(about?.sshd && about?.recidive, locale);
  }
});

test("a v7 jail's bans are not called site-only", () => {
  const card = read("components/applications/fail2ban/banned-addresses-card.jsx");
  assert.match(card, /const serverWide = Boolean\(name\) && !name\.startsWith\("panel-site-"\);/);
  assert.match(card, /t\(serverWide \? "subtitleServerWide" : "subtitle"\)/);
});

test("legacy_v7 allows exempt every bot containing the name", () => {
  assert.equal(isAllowedBot(["GPT"], "GPTBot"), false);
  assert.equal(isAllowedBot(["GPT"], "GPTBot", { contains: true }), true);
  assert.deepEqual(effectiveBlockedBots(["GPTBot", "ClaudeBot"], [], ["gpt"], { contains: true }), ["ClaudeBot"]);
  assert.deepEqual(effectiveBlockedBots(["GPTBot", "ClaudeBot"], [], ["gpt"]), ["GPTBot", "ClaudeBot"]);
});

test("legacy_v7 stands alone and is offered only to a site already on it", () => {
  const src = read("components/applications/bot-blocker/bot-blocker-section.jsx");
  assert.match(src, /orderedKeys\(policies, base\.policy === LEGACY \|\| policy === LEGACY\)/);
  assert.match(src, /if \(selected === LEGACY\) \{/);
  assert.match(src, /if \(key === LEGACY\) continue;/);
});
