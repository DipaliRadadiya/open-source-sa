import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Reported on the bot traffic card: "this 3 buttons not looks like clickable
 * buttons of 7 days, 30 days, 90 days."
 *
 * They were `variant="ghost"` until selected — no border, no fill — so two of
 * the three rendered as plain text, and the row read as a caption rather than
 * a control. The log viewer's severity filter had exactly the same fault for
 * exactly the same reason, and the two had been fixed independently, to two
 * slightly different answers.
 *
 * Both now read their look from one definition. Sharing the CLASSES rather
 * than extracting a component, because the two render differently: the
 * severity filter is a <button> with an onClick, the range picker an <a> so
 * the choice survives a reload. The markup is not what drifts; the look is.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const chrome = read("lib/theme/filter-toggle.js");
const range = read("components/applications/bot-blocker/bot-traffic-card.jsx");
const toolbar = read("components/logs/log-toolbar.jsx");

test("there is one definition of what a filter toggle looks like", () => {
  assert.match(chrome, /export function filterToggleClass\(active\)/);
  // An edge in both states: rank belongs in colour, never in whether a control
  // has a surface at all.
  assert.match(chrome, /border-primary\/40 bg-primary\/10 font-medium text-primary/);
  assert.match(chrome, /border-input text-muted-foreground hover:bg-muted hover:text-foreground/);
});

test("both controls read from it rather than restating it", () => {
  for (const [file, source] of [["bot-traffic-card", range], ["log-toolbar", toolbar]]) {
    assert.match(source, /import \{ filterToggleClass \} from "@\/lib\/theme\/filter-toggle"/, file);
    assert.match(source, /filterToggleClass\(/, file);
    // A hand-written copy is how the two came to disagree in the first place.
    const code = source.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
    assert.doesNotMatch(code, /border-primary\/40 bg-primary\/10/, `${file} restates the active look`);
  }
});

test("the range picker is never ghost again", () => {
  const code = range.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
  assert.match(code, /variant="outline"/);
  assert.doesNotMatch(code, /"secondary" : "ghost"/);
  // 28px in a row of 32s was the other half of why it read as text.
  assert.match(code, /h-8 px-2\.5 text-xs/);
  assert.doesNotMatch(code, /h-7 px-2 text-xs/);
});
