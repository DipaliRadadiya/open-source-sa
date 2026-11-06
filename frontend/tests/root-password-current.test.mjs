// RC-26: the dialog asks for root's current password only after the API says it cannot reach root.
import { afterEach, before, beforeEach, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { act, cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");

const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const all = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(readFileSync(`messages/${l}.json`, "utf8"))]));
const messages = all.en;
const m = messages.databases.rootPassword;
const NEW = "a-new-root-password-1";
const refuse = (reason, message) => {
  const error = new Error("422");
  error.response = { status: 422, data: { message, reason, errors: { current_password: [message] } } };
  throw error;
};

let RootPasswordDialog;
let TooltipProvider;
before(async () => {
  TooltipProvider = (await loadComponent("components/ui/tooltip.jsx")).TooltipProvider;
  ({ RootPasswordDialog } = await loadComponent("components/databases/root-password-dialog.jsx", {
    "@/lib/api/databases": "tests/support/root-password-api-stub.js",
    "next/navigation": "tests/support/next-navigation-stub.js",
    sonner: "tests/support/sonner-stub.js",
  }));
});
beforeEach(() => {
  globalThis.__rootPasswordCalls = [];
  globalThis.__toasts = [];
});
afterEach(() => cleanup());

function show() {
  render(createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" },
    createElement(TooltipProvider, null,
      createElement(RootPasswordDialog, { engine: "mariadb", connection: { has_root_password: false }, open: true, onOpenChange: () => {} }))));
}
const fill = (label, value) => fireEvent.change(screen.getByLabelText(new RegExp(`^${label}`)), { target: { value } });
const submit = () => act(async () => fireEvent.click(screen.getByRole("button", { name: m.submitSet })));

test("no current-password field until the API asks; then it appears with its hint and no error", async () => {
  globalThis.__rootPassword = () => refuse("root_unreachable", "Root refused the panel. Enter root's current password.");
  show();
  assert.equal(screen.queryByLabelText(new RegExp(`^${m.current}`)), null);
  fill(m.new, NEW);
  fill(m.confirm, NEW);
  await submit();
  await waitFor(() => assert.ok(screen.getByLabelText(new RegExp(`^${m.current}`))));
  assert.equal(globalThis.__rootPasswordCalls[0].values.current_password, undefined, "first try sends no current password");
  assert.ok(document.body.textContent.includes(m.currentHint.replace("{engine}", "MariaDB")));
  assert.equal(document.body.textContent.includes("Root refused the panel"), false, "the question is not shown as a mistake");
  assert.deepEqual(globalThis.__toasts, []);
});

test("left empty, it is required; filled, it is sent and the change saves", async () => {
  let calls = 0;
  globalThis.__rootPassword = () => (calls++ === 0 ? refuse("root_unreachable", "Enter it.") : { data: { message: "ok" } });
  show();
  fill(m.new, NEW);
  fill(m.confirm, NEW);
  await submit();
  await waitFor(() => screen.getByLabelText(new RegExp(`^${m.current}`)));
  await submit();
  assert.equal(globalThis.__rootPasswordCalls.length, 1, "an empty current password is not sent");
  assert.ok(document.body.textContent.includes(messages.common.requiredFieldNamed.replace("{field}", m.current)));
  fill(m.current, "old-root-secret");
  await submit();
  await waitFor(() => assert.equal(globalThis.__rootPasswordCalls.length, 2));
  assert.deepEqual(globalThis.__rootPasswordCalls[1].values, { password: NEW, password_confirmation: NEW, current_password: "old-root-secret" });
});

test("a wrong current password keeps the field open and says so", async () => {
  let calls = 0;
  globalThis.__rootPassword = () =>
    calls++ === 0 ? refuse("root_unreachable", "Enter it.") : refuse("current_password_wrong", "That password did not sign in as root.");
  show();
  fill(m.new, NEW);
  fill(m.confirm, NEW);
  await submit();
  await waitFor(() => screen.getByLabelText(new RegExp(`^${m.current}`)));
  fill(m.current, "wrong");
  await submit();
  await waitFor(() => assert.ok(document.body.textContent.includes("That password did not sign in as root.")));
  assert.ok(screen.getByLabelText(new RegExp(`^${m.current}`)));
});

test("the three new strings exist and are translated", () => {
  for (const locale of LOCALES) {
    const r = all[locale].databases.rootPassword;
    assert.ok(r.current && r.currentPlaceholder && r.currentHint.includes("{engine}"), locale);
    if (locale !== "en") assert.notEqual(r.currentHint, m.currentHint, locale);
  }
});
