import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));

let PhpCommonSettings, TooltipProvider, saves;
const SETTINGS = { memory_limit: "128M", upload_max_filesize: "2M", max_execution_time: 30, max_input_time: 60, max_input_vars: null, post_max_size: "8M" };

before(async () => {
  ({ PhpCommonSettings } = await loadComponent("components/php/php-common-settings.jsx", {
    "@/lib/api/php": "tests/support/php-api-stub.js",
    "next/navigation": "tests/support/next-navigation-stub.js",
    sonner: "tests/support/sonner-stub.js",
  }));
  ({ TooltipProvider } = await loadComponent("components/ui/tooltip.jsx"));
});

beforeEach(() => {
  saves = [];
  globalThis.__toasts = [];
  globalThis.__phpApi = {
    readPhpSettings: async () => ({ data: { version: "8.4", settings: SETTINGS } }),
    savePhpSettings: async (version, payload) => {
      saves.push(payload);
      return { data: { version: "8.4", settings: { ...SETTINGS, ...payload } } };
    },
  };
});
afterEach(() => cleanup());

async function mount() {
  render(createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" },
    createElement(TooltipProvider, null, createElement(PhpCommonSettings, { version: "8.4", canManage: true }))));
  await waitFor(() => assert.ok(document.querySelector('input[name="memory_limit"]'), document.body.textContent.slice(0, 300)));
}
const memory = () => document.querySelector('input[name="memory_limit"]');
const type = (value) => fireEvent.change(memory(), { target: { value } });
const save = () => fireEvent.click(screen.getByRole("button", { name: messages.php.common.saveAction }));
const errors = () => [...document.querySelectorAll("[data-slot=form-message]")].map((e) => e.textContent).filter(Boolean);

test("a wrong size is refused with the rule", async () => {
  await mount();
  type("abc");
  save();
  await waitFor(() => assert.deepEqual(errors(), [messages.validation.phpSize]));
  assert.deepEqual(saves, []);
});

test("after a wrong size, correcting it clears the error and Save sends it", async () => {
  await mount();
  type("abc");
  save();
  await waitFor(() => assert.equal(errors().length, 1));
  type("256M");
  await waitFor(() => assert.deepEqual(errors(), []));
  save();
  await waitFor(() => assert.deepEqual(saves, [{ memory_limit: "256M" }]));
});
