import assert from "node:assert/strict";
import fs from "node:fs";
import test from "node:test";

// Users (4 Nov): "nicer graphic switch" for PHP extensions. Small switches, an off state that was
// dark grey on a dark card, and panel-required rows that looked like broken faded switches.
const read = (path) => fs.readFileSync(new URL(`../${path}`, import.meta.url), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];

test("the switch has a large size with a visible off track, used only by the extensions list", () => {
  const sw = read("components/ui/switch.jsx");
  assert.match(sw, /data-\[size=lg\]:h-6 data-\[size=lg\]:w-11/);
  assert.match(sw, /data-\[size=lg\]:data-unchecked:bg-muted-foreground\/35 dark:data-\[size=lg\]:data-unchecked:bg-muted-foreground\/45/);
  assert.match(sw, /group-data-\[size=lg\]\/switch:size-5/);
  // The default switch is unchanged everywhere else.
  assert.match(sw, /data-\[size=default\]:h-\[18\.4px\] data-\[size=default\]:w-\[32px\]/);
  assert.match(read("components/php/extensions-card.jsx"), /<Switch\s+size="lg"/);
});

test("each switch says On or Off in words, in every locale", () => {
  const card = read("components/php/extensions-card.jsx");
  assert.match(card, /: extension\.enabled\s*\?\s*t\("extensions\.stateOn"\)\s*:\s*t\("extensions\.stateOff"\)/);
  for (const l of LOCALES) {
    const e = JSON.parse(read(`messages/${l}.json`)).php.extensions;
    assert.ok(e.stateOn && e.stateOff && e.stateOn !== e.stateOff, l);
  }
});

test("a row the panel needs shows a lock and the reason, not a faded switch", () => {
  const card = read("components/php/extensions-card.jsx");
  assert.match(card, /\{required \? \(/);
  assert.match(card, /<Lock className="size-3\.5 shrink-0" aria-hidden \/>\s*\{t\("extensions\.panelNeedsShort"\)\}/);
  assert.match(card, /title=\{t\("extensions\.panelNeeds"\)\}/);
});

test("a switch is locked while its own install runs, and says so", () => {
  // Switching off mid-install was undone by the install's last step (phpenmod) a second later.
  const card = read("components/php/extensions-card.jsx");
  assert.match(card, /const installing = extension\.status === "installing";/);
  assert.match(card, /: installing\s*\?\s*t\("extensions\.waitForOther", \{ name: extension\.name \}\)/);
  assert.match(card, /\{installing\s*\?\s*t\("extensions\.installingNow"\)/);
});
