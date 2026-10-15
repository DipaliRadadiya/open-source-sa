import assert from "node:assert/strict";
import { afterEach, before, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";
import { applicationSchema } from "../lib/schemas/application.js";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { render, screen, cleanup, fireEvent } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
let Badge;
let TooltipProvider;
before(async () => {
  Badge = (await loadComponent("components/applications/application-origin-badge.jsx")).ApplicationOriginBadge;
  TooltipProvider = (await loadComponent("components/ui/tooltip.jsx")).TooltipProvider;
});
afterEach(cleanup);
const recipe = { kind: "recipe", recipe: { slug: "ghost", title: "Ghost", version: 1, deprecated: false }, image: null };
function show(origin, compact = false) {
  return render(createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" },
    createElement(TooltipProvider, null, createElement(Badge, { origin, compact }))));
}
for (const [origin, label] of [[recipe, "One-click app"], [{ kind: "image", image: "nginx:1.27" }, "Docker image"], [{ kind: "compose" }, "Docker Compose"]]) {
  test(`${origin.kind} renders its label as a neutral pill beside the status pill`, () => {
    show(origin);
    const badge = screen.getByLabelText(label);
    assert.equal(badge.dataset.variant, "muted");
    assert.equal(badge.textContent, label);
    assert.ok(badge.querySelector("svg.size-3"));
  });
}
for (const origin of [undefined, null, { kind: "other" }, { kind: "future" }]) {
  test(`missing/other origin ${JSON.stringify(origin)} renders nothing`, () => {
    assert.equal(show(origin).container.textContent, "");
    assert.equal(document.querySelector('[data-slot="badge"]'), null);
  });
}
test("compact keeps an accessible label and hides only its text below sm", () => {
  show(recipe, true);
  const badge = screen.getByLabelText("One-click app");
  assert.equal(badge.tabIndex, 0);
  assert.equal(badge.querySelector("span").className, "hidden sm:inline");
});
for (const [origin, tooltip] of [[recipe, "Installed from the tested Ghost recipe (version 1)."], [{ kind: "image", image: "nginx:1.27" }, "Deployed from the image nginx:1.27."], [{ kind: "compose" }, "Runs the compose file you provided."]]) {
  test(`${origin.kind} explains its source on keyboard focus`, async () => {
    show(origin);
    fireEvent.focus(screen.getByLabelText(messages.applications.origin[origin.kind]));
    assert.equal((await screen.findByRole("tooltip")).textContent, tooltip);
  });
}
test("schema preserves all origin metadata and defaults deprecated", () => {
  const base = { id: 1, name: "Ghost", site_type: "ghost" };
  assert.deepEqual(applicationSchema.parse({ ...base, origin: recipe }).origin, recipe);
  assert.equal(applicationSchema.parse({ ...base, origin: { kind: "recipe", recipe: { slug: "ghost", title: "Ghost", version: 1 } } }).origin.recipe.deprecated, false);
  assert.equal(applicationSchema.parse({ ...base, origin: { kind: "future", recipe: null, image: null } }).origin.kind, "other");
  assert.equal(applicationSchema.parse(base).origin, undefined);
  assert.equal(applicationSchema.parse({ ...base, origin: null }).origin, null);
});
