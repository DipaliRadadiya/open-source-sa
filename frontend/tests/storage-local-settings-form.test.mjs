import { afterEach, before, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const settings = { allowed_roots: ["/srv/archive"], default_path: "/srv/archive/panel" };
let ConnectDestinationDialog, EditDestinationDialog, TooltipProvider;
before(async () => {
  TooltipProvider = (await loadComponent("components/ui/tooltip.jsx")).TooltipProvider;
  const stubs = { "next/navigation": "tests/support/next-navigation-stub.js", sonner: "tests/support/sonner-stub.js" };
  ({ ConnectDestinationDialog } = await loadComponent("components/integrations/storage/connect-dialog.jsx", stubs));
  ({ EditDestinationDialog } = await loadComponent("components/integrations/storage/edit-dialog.jsx", stubs));
});
afterEach(() => cleanup());
const wrap = (component) => createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" }, createElement(TooltipProvider, null, component));
const pathInput = () => screen.getByLabelText(new RegExp(`^${messages.storage.form.fields.path}`));

test("create shows the advertised default and hint, and cancelling restores that default", async () => {
  const props = { open: true, initialPreset: "local", localSettings: settings, onOpenChange() {} };
  const view = render(wrap(createElement(ConnectDestinationDialog, props)));
  assert.equal(pathInput().value, settings.default_path);
  assert.equal(pathInput().placeholder, settings.default_path);
  assert.ok(document.body.textContent.includes("/srv/archive"));
  assert.ok(!document.body.textContent.includes("/var/backups"));
  fireEvent.change(pathInput(), { target: { value: "/srv/archive/other" } });
  fireEvent.click(screen.getByRole("button", { name: messages.storage.connect.cancel, exact: true }));
  view.rerender(wrap(createElement(ConnectDestinationDialog, { ...props, open: false })));
  view.rerender(wrap(createElement(ConnectDestinationDialog, props)));
  await waitFor(() => assert.equal(pathInput().value, settings.default_path));
});

test("edit keeps the saved path, advertises the current default and preserves the move warning", () => {
  render(wrap(createElement(EditDestinationDialog, { open: true, onOpenChange() {}, localSettings: settings,
    destination: { id: 10, name: "Existing", provider: "local", config: { path: "/srv/archive/saved" } } })));
  assert.equal(pathInput().value, "/srv/archive/saved");
  assert.equal(pathInput().placeholder, settings.default_path);
  assert.ok(document.body.textContent.includes(messages.storage.form.localPathChangeWarning));
});

test("a response without local metadata still opens a working local form", () => {
  render(wrap(createElement(ConnectDestinationDialog, { open: true, initialPreset: "local", onOpenChange() {} })));
  assert.equal(pathInput().value, "/var/backups/panel");
  assert.ok(document.body.textContent.includes("/var/backups, /mnt, /media"));
  assert.ok(!document.body.textContent.includes("{roots}"));
});
