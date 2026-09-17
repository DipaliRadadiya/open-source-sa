import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported across three screens: "this all even not looks like notes
 * information."
 *
 * The cause was that no such component existed. `Caution` covers warnings —
 * amber, bordered, icon — but there was nothing for plain information, so four
 * screens each hand-rolled a grey box, and two of them did not even agree on
 * whether to show an icon. A grey block on a white page reads as empty space,
 * not as something worth reading.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const note = read("components/ui/note.jsx");
const caution = read("components/ui/caution.jsx");

const USERS = [
  "components/applications/firewall/firewall-section.jsx",
  "components/applications/bot-blocker/bot-blocker-section.jsx",
  "components/applications/security/security-section.jsx",
];

test("Note is built to the same grammar as Caution", () => {
  // Tinted surface, matching border, coloured icon — so the two read as
  // siblings: amber for "be careful", accent for "here is what this is".
  assert.match(note, /rounded-lg border border-primary\/20 bg-primary\/5 p-3 text-sm/);
  assert.match(note, /className="mt-0\.5 size-4 shrink-0 text-primary"/);
  assert.match(caution, /rounded-lg border border-warning\/40 bg-warning\/10/);
});

test("the body is a div, so a note can hold more than one sentence", () => {
  // Callers pass plain sentences today, but a list or a line with a link is a
  // block element and a <p> wrapping one is invalid HTML.
  assert.match(note, /<div className="text-muted-foreground">\{children\}<\/div>/);
});

test("the title is optional and the icon defaults", () => {
  // A single sentence needs no heading over it; "When to use this" does.
  assert.match(note, /\{title \? <p className="font-medium text-foreground">\{title\}<\/p> : null\}/);
  assert.match(note, /icon: Icon = Info/);
});

test("every screen uses it, and none still hand-rolls a grey box", () => {
  for (const file of USERS) {
    const source = read(file);
    assert.match(source, /import \{ Note \} from "@\/components\/ui\/note"/, `${file} does not use the shared Note`);
    const code = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
    assert.doesNotMatch(code, /bg-muted\/40 px-4 py-3 text-sm text-muted-foreground/, `${file} still hand-rolls a note`);
    assert.doesNotMatch(code, /rounded-lg bg-muted\/40 p-3\.5/, `${file} still hand-rolls a note`);
  }
});

test("a note carries its own feature's mark", () => {
  // A note about the firewall should look like the firewall, not like a
  // generic info bubble.
  const firewall = read("components/applications/firewall/firewall-section.jsx");
  const bots = read("components/applications/bot-blocker/bot-blocker-section.jsx");
  assert.match(firewall, /<Note icon=\{ShieldCheck\}>/);
  assert.match(bots, /<Note icon=\{Bot\}>/);
  assert.match(firewall, /<Note icon=\{Lightbulb\} title=\{t\("whenToUseTitle"\)\}>/);
});

test("nothing else in the panel is called Note", () => {
  /*
   * `account-health.jsx` had a file-local `Note` — a line of tiny coloured
   * status text, a completely different thing. No collision while it stayed
   * local, but it would shadow the shared one the moment anyone imported it
   * there, which is exactly the drift this component exists to end.
   */
  const health = read("components/integrations/git/account-health.jsx");
  assert.match(health, /function StatusText\(\{ children, tone \}\)/);
  assert.doesNotMatch(health, /function Note\(/);
});
