import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

/**
 * The collapsed rail expands on hover and floats over the page.
 *
 * The behaviour is expressed in Tailwind data-attribute variants rather than in
 * JS style writes, so these assert the class contract. That is a weaker test
 * than driving a browser — it cannot tell you the panel *looks* right — but it
 * does pin the three decisions that are easy to undo by accident and expensive
 * to notice: the gap staying narrow, the elevation, and the touch gate.
 */
const sidebar = readFileSync("components/ui/sidebar.jsx", "utf8");
const hook = readFileSync("hooks/use-hover-intent.js", "utf8");

test("peeking is expressed by clearing data-collapsible, not by width overrides", () => {
  // Every descendant already hides its label on `collapsible=icon`. Pretending
  // to be expanded makes all of them correct at once; the alternative was a
  // width override plus a dozen `group-data-[peek=true]:` unhides, each of
  // which is a place to forget one.
  assert.match(sidebar, /data-collapsible=\{state === "collapsed" && !peeking \? collapsible : ""\}/);
  assert.match(sidebar, /data-peek=\{peeking \? "true" : undefined\}/);
});

test("the layout gap stays at rail width while peeking", () => {
  // THE load-bearing assertion. If the gap follows the panel, the whole page
  // slides sideways every time the pointer touches the rail — which is the
  // opposite of the effect and genuinely unpleasant over a wide table.
  assert.match(sidebar, /group-data-\[peek=true\]:w-\(--sidebar-width-icon\)/);
  assert.match(sidebar, /group-data-\[peek=true\]:w-\[calc\(var\(--sidebar-width-icon\)\+\(--spacing\(4\)\)\)\]/);
});

test("the peeking panel is lifted above the page", () => {
  // It overlaps content now, so it needs a shadow to read as a layer — and
  // z-10 sits below this app's sticky header, which would clip its top.
  assert.match(sidebar, /group-data-\[peek=true\]:z-30/);
  assert.match(sidebar, /group-data-\[peek=true\]:shadow-xl/);
});

test("the width transition is no longer linear, and reduced motion opts out", () => {
  // `ease-linear` starts and stops at the same speed, which is what made the
  // collapse feel mechanical.
  assert.match(sidebar, /cubic-bezier\(0\.32,\s*0\.72,\s*0,\s*1\)/);
  assert.doesNotMatch(
    sidebar,
    /transition-\[left,right,width\][^"]*ease-linear/,
    "the sidebar container should not still be on ease-linear",
  );

  // The project uses motion-reduce: everywhere; a sidebar that lunges at
  // someone with vestibular sensitivity is a real problem, not a preference.
  const motionReduce = sidebar.match(/motion-reduce:transition-none/g) ?? [];
  assert.ok(motionReduce.length >= 2, "both the gap and the panel must opt out of motion");
});

test("touch never triggers a peek", () => {
  // A touch "hover" fires on tap and sticks until the next tap elsewhere, so on
  // a tablet the panel would open on the first tap and swallow the one the user
  // actually meant.
  assert.match(hook, /\(hover: hover\) and \(pointer: fine\)/);
  assert.match(hook, /event\.pointerType === "touch"/);

  // Gated twice on purpose: a touchscreen laptop matches the media query and
  // still sends touch events, so the query alone is not enough.
  const touchGuards = hook.match(/pointerType === "touch"/g) ?? [];
  assert.equal(touchGuards.length, 2, "both enter and leave must ignore touch");
});

test("opening waits longer than a pass-through, and closing waits longer still", () => {
  // The rail runs the full height of the left edge; every trip to the browser
  // chrome crosses it. Without intent delays the panel would flap constantly.
  const enter = Number(hook.match(/enterDelay = (\d+)/)?.[1]);
  const leave = Number(hook.match(/leaveDelay = (\d+)/)?.[1]);

  assert.ok(enter >= 80, `enter delay ${enter}ms is too short to ignore a pass-through`);
  assert.ok(leave > enter, "closing must be slower than opening, or it snaps shut mid-reach");
});

test("keyboard focus opens the rail immediately", () => {
  // Tabbing into a collapsed rail otherwise lands a keyboard user in a column
  // of unlabelled icons. There is no ambiguity to wait out, so no delay.
  assert.match(hook, /onFocusCapture/);
  assert.match(hook, /onBlurCapture/);
  // Blur fires when moving between two items inside the rail too; closing on
  // that would shut the panel mid-navigation.
  assert.match(hook, /currentTarget\.contains\(event\.relatedTarget\)/);
});

test("a stale hover cannot spring the panel open later", () => {
  // Found by the linter, but it was a real defect: resetting in an effect left
  // `hovered` true, so the next time the rail collapsed it would peek with no
  // pointer near it. Reset during render instead.
  assert.match(hook, /const \[wasActive, setWasActive\] = React\.useState\(active\)/);
  assert.match(hook, /if \(wasActive !== active\)/);
});
