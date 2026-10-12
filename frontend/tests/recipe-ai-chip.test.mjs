/*
 * RC-22: the 1-Click Recipes tab gets an "AI" chip, first among the category
 * buckets, and the recipes' `development` category stops landing in Others.
 * Renders the real SiteTypePicker (jsdom + testing-library).
 */
import { after, before, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";
import { groupForType, groupsWithTypes } from "../lib/applications/type-categories.js";

installDom();
const { createElement } = await import("react");
const { cleanup, fireEvent, render, screen } = await import("@testing-library/react");

const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
let SiteTypePickerHarness;

before(async () => {
  ({ SiteTypePickerHarness } = await loadComponent("tests/support/site-type-picker-harness.jsx", {
    "@/components/ui/app-link": "tests/support/app-link-stub.jsx",
    "next/navigation": "tests/support/next-navigation-stub.js",
  }));
});
after(() => cleanup());

const recipe = (name, title, category, popular = false) => ({
  name,
  title,
  category,
  popular,
  available: true,
  tagline: `${title} tagline`,
});

const CATALOG = [
  recipe("wordpress-docker", "WordPress", "cms", true),
  recipe("gitea", "Gitea", "development"),
  recipe("n8n", "n8n", "automation", true),
  recipe("langflow", "Langflow", "ai"),
];

function chipNames() {
  return [...document.querySelectorAll("button[aria-pressed]")].map((el) => el.textContent);
}

test("ai and development categories map to their own buckets", () => {
  assert.equal(groupForType({ category: "ai" }), "ai");
  assert.equal(groupForType({ category: "AI" }), "ai");
  assert.equal(groupForType({ category: "development" }), "development");
  assert.equal(groupForType({ category: "developer" }), "development");
});

test("the AI bucket comes first among the category buckets", () => {
  const groups = groupsWithTypes(CATALOG);
  assert.equal(groups[0].key, "ai");
  assert.deepEqual(groups.map((g) => g.key), ["ai", "cms", "development", "tools"]);
});

test("the AI chip renders right after Popular and filters to AI recipes", () => {
  cleanup();
  render(createElement(SiteTypePickerHarness, { messages, types: CATALOG }));
  assert.deepEqual(chipNames(), ["Popular", "AI", "CMS", "Development", "Tools", "All"]);

  fireEvent.click(screen.getByRole("button", { name: "AI" }));
  assert.equal(screen.getByRole("button", { name: "AI" }).getAttribute("aria-pressed"), "true");
  assert.ok(screen.getByText("Langflow"));
  assert.equal(screen.queryByText("WordPress"), null);
  assert.equal(screen.queryByText("Gitea"), null);
});

test("Gitea is under Development, not Others", () => {
  cleanup();
  render(createElement(SiteTypePickerHarness, { messages, types: CATALOG }));
  assert.equal(screen.queryByRole("button", { name: "Others" }), null);
  fireEvent.click(screen.getByRole("button", { name: "Development" }));
  assert.ok(screen.getByText("Gitea"));
});

test("no AI chip when no recipe is in the ai category", () => {
  cleanup();
  render(createElement(SiteTypePickerHarness, {
    messages,
    types: CATALOG.filter((type) => type.category !== "ai"),
  }));
  assert.equal(screen.queryByRole("button", { name: "AI" }), null);
  assert.deepEqual(chipNames(), ["Popular", "CMS", "Development", "Tools", "All"]);
});

test("every locale labels the AI chip", () => {
  const expected = { en: "AI", es: "IA", de: "KI", fr: "IA", pt: "IA", ja: "AI", ru: "ИИ", hi: "AI" };
  for (const [locale, label] of Object.entries(expected)) {
    const m = JSON.parse(readFileSync(`messages/${locale}.json`, "utf8"));
    assert.equal(m.applications.form.categoryAi, label, locale);
  }
});
