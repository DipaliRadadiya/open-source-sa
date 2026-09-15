import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

/**
 * The sidebar slides when it changes level.
 *
 * Opening a site swaps the whole menu — server nav out, application nav in —
 * and that swap used to be instantaneous, so it read as "the menu was replaced"
 * rather than "you went somewhere". The direction is the part that carries
 * meaning: in from the right going deeper, in from the left coming back.
 */
const component = readFileSync("components/sections/sidebar-level-transition.jsx", "utf8");
const sidebar = readFileSync("components/sections/app-sidebar.jsx", "utf8");

test("direction is opposite for going in and coming back", () => {
  // Animating both the same way would be decoration. Opposite directions are
  // what tell you which way you just went.
  assert.match(component, /direction === "forward" \? "slide-in-from-right-4" : "slide-in-from-left-4"/);
  assert.match(component, /level === "server" \? "backward" : "forward"/);
});

test("the remount key is NOT on the element holding the direction state", () => {
  /*
   * This is the bug this test exists for, and it was live for about a minute.
   *
   * Remounting is what replays a CSS animation. But if the key sits on the same
   * component that holds the direction state, every level change remounts it
   * and resets that state — so direction reads "forward" forever and the back
   * navigation slides the WRONG WAY. It would still animate, which is exactly
   * why nobody would notice.
   */
  const keyLine = component.match(/^\s*key=\{level\}/m);
  assert.ok(keyLine, "the inner element must be keyed on the level");

  // The state hook must appear before the keyed element — i.e. it lives in the
  // component, above the thing that remounts.
  const stateIndex = component.indexOf("useLevelDirection(level)");
  const keyIndex = component.indexOf("key={level}");

  assert.ok(stateIndex > -1 && keyIndex > -1);
  assert.ok(
    stateIndex < keyIndex,
    "direction must be computed outside the element that remounts, or it resets every time",
  );
});

test("moving between two applications counts as a level change", () => {
  // `currentPanel` stays "application" the whole way, but to the person doing
  // it the menu changed — so the id is part of the key.
  assert.match(sidebar, /level=\{insideApplication \? `application:\$\{applicationId\}` : "server"\}/);
});

test("the transition is short, shallow, and skipped under reduced motion", () => {
  // It fires on every navigation into or out of a site. Anything longer stops
  // being a transition and starts being a wait.
  assert.match(component, /duration-200/);
  assert.match(component, /slide-in-from-(right|left)-4/);

  // The sidebar is on screen for the whole session; this is not a preference.
  assert.match(component, /motion-reduce:animate-none/);
});

test("only the incoming menu animates", () => {
  // A cross-fade would keep the outgoing menu mounted, and its items derive
  // `active` from the live pathname — so the outgoing copy would re-render
  // mid-flight highlighting the NEW page's item, which looks like a bug.
  assert.match(component, /animate-in/);
  assert.doesNotMatch(component, /animate-out/, "no exit animation: the outgoing tree would desync");
});
