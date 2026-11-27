import assert from "node:assert/strict";
import { afterEach, before, test } from "node:test";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { cleanup, fireEvent, render, screen } = await import("@testing-library/react");

let Button, TooltipProvider;
before(async () => {
  ({ Button } = await loadComponent("components/ui/button.jsx"));
  ({ TooltipProvider } = await loadComponent("components/ui/tooltip.jsx"));
});
afterEach(() => cleanup());

const mount = (...children) => render(createElement(TooltipProvider, null, createElement("form", { onSubmit: (e) => e.preventDefault() }, createElement("input", { name: "f" }), ...children)));
// fireEvent returns false when a handler called preventDefault().
const pressKeepsFocus = (name) => !fireEvent.mouseDown(screen.getByRole("button", { name }));

test("pressing a submit button leaves the focus in the field, so its blur check can't move the button mid-click", () => {
  mount(createElement(Button, { type: "submit" }, "Save"));
  assert.equal(pressKeepsFocus("Save"), true);
});

test("ordinary buttons still take focus on press", () => {
  mount(createElement(Button, { type: "button" }, "Discard"), createElement(Button, null, "Plain"));
  assert.equal(pressKeepsFocus("Discard"), false);
  assert.equal(pressKeepsFocus("Plain"), false);
});

test("a submit button's own onMouseDown still runs", () => {
  const seen = [];
  mount(createElement(Button, { type: "submit", onMouseDown: () => seen.push("own") }, "Save"));
  pressKeepsFocus("Save");
  assert.deepEqual(seen, ["own"]);
});

test("the click still submits the form", () => {
  let submitted = 0;
  render(createElement(TooltipProvider, null, createElement("form", { onSubmit: (e) => { e.preventDefault(); submitted++; } }, createElement(Button, { type: "submit" }, "Save"))));
  const btn = screen.getByRole("button", { name: "Save" });
  fireEvent.mouseDown(btn); fireEvent.mouseUp(btn); fireEvent.click(btn);
  assert.equal(submitted, 1);
});
