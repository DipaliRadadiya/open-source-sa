import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { act, cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const d = messages.applications.domains;
const v = messages.validation;

let AddDomainDialog, EditDomainDialog, TooltipProvider;
let calls, closes, answer;

before(async () => {
  const stubs = {
    "@/lib/api/domains": "tests/support/domains-api-stub.js",
    "@/hooks/use-refresh": "tests/support/use-refresh-stub.js",
    sonner: "tests/support/sonner-stub.js",
  };
  ({ AddDomainDialog } = await loadComponent("components/applications/domains/add-domain-dialog.jsx", stubs));
  ({ EditDomainDialog } = await loadComponent("components/applications/domains/edit-domain-dialog.jsx", stubs));
  ({ TooltipProvider } = await loadComponent("components/ui/tooltip.jsx"));
});

beforeEach(() => {
  calls = [];
  closes = [];
  answer = () => ({ id: 1 });
  globalThis.__toasts = [];
  globalThis.__toastDescriptions = [];
  globalThis.__domainsApi = {
    addDomain: async (...args) => {
      calls.push({ name: "addDomain", args });
      return answer(args[1]);
    },
    updateDomain: async (...args) => {
      calls.push({ name: "updateDomain", args });
      return answer(args[2]);
    },
  };
});
afterEach(() => cleanup());

const wrap = (child) =>
  createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" }, createElement(TooltipProvider, null, child));

function mountAdd(props = {}) {
  const all = { appId: 3, open: true, onOpenChange: (next) => closes.push(next), ...props };
  const view = render(wrap(createElement(AddDomainDialog, all)));
  return { ...view, rerender: (p) => view.rerender(wrap(createElement(AddDomainDialog, { ...all, ...p }))) };
}

const ALIAS = { id: 5, domain: "www.example.com", type: "alias", redirect_to: null, redirect_status: null };
const REDIRECT = { id: 6, domain: "old.example.com", type: "redirect", redirect_to: "https://example.com", redirect_status: 307 };

function mountEdit(props = {}) {
  const all = { appId: 3, domain: ALIAS, open: true, onOpenChange: (next) => closes.push(next), ...props };
  const view = render(wrap(createElement(EditDomainDialog, all)));
  return { ...view, rerender: (p) => view.rerender(wrap(createElement(EditDomainDialog, { ...all, ...p }))) };
}

const field = (name) => document.querySelector(`[role=dialog] [name="${name}"]`);
const type = (name, value) => fireEvent.change(field(name), { target: { value } });
const submit = (label) => fireEvent.click(screen.getByRole("button", { name: label }));
const combos = () => screen.getAllByRole("combobox");

async function choose(combo, optionName) {
  act(() => combo.focus());
  fireEvent.keyDown(combo, { key: "Enter" });
  fireEvent.click(await screen.findByRole("option", { name: optionName }));
}

const serverError = (sent, status, data) => ({ config: { data: JSON.stringify(sent) }, response: { status, data } });

// ---------- Add a domain ----------

test("add: opens empty as an alias, with no redirect fields", () => {
  mountAdd();
  assert.ok(screen.getByRole("dialog", { name: d.add.title }));
  assert.equal(field("domain").value, "");
  assert.equal(combos().length, 1);
  assert.match(combos()[0].textContent, /Alias/);
  assert.ok(screen.getByText(d.add.aliasHint));
  assert.equal(field("redirect_to"), null);
});

test("add: an empty name is refused before any request", async () => {
  mountAdd();
  submit(d.add.submit);
  assert.ok(await screen.findByText(v.domainRequired));
  assert.equal(calls.length, 0);
});

test("add: a pasted URL offers the bare hostname, and the offer fills it in", async () => {
  mountAdd();
  type("domain", "https://Shop.Example.com/path");
  const offer = await screen.findByRole("button", { name: "Use shop.example.com" });
  fireEvent.click(offer);
  await waitFor(() => assert.equal(field("domain").value, "shop.example.com"));
  assert.equal(screen.queryByRole("button", { name: "Use shop.example.com" }), null);
});

test("add: an alias sends only the name and type, trimmed and lower-cased, then closes", async () => {
  mountAdd();
  type("domain", "  Shop.Example.com ");
  submit(d.add.submit);
  await waitFor(() => assert.deepEqual(closes, [false]));
  assert.deepEqual(calls, [{ name: "addDomain", args: [3, { domain: "shop.example.com", type: "alias" }] }]);
  assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: d.toast.added }]);
  assert.deepEqual(globalThis.__toastDescriptions, []);
});

test("add: a redirect needs a full http(s) address and sends target and status", async () => {
  mountAdd();
  type("domain", "old.example.com");
  await choose(combos()[0], d.type.redirect);
  assert.ok(await screen.findByText(d.add.redirectHint));
  assert.ok(field("redirect_to"));
  submit(d.add.submit);
  assert.ok(await screen.findByText(v.redirectTargetRequired));
  type("redirect_to", "example.org");
  submit(d.add.submit);
  assert.ok(await screen.findByText(v.redirectTargetUrl));
  assert.equal(calls.length, 0);
  type("redirect_to", "https://example.org");
  await choose(combos()[1], d.add.redirectStatusOption["302"]);
  submit(d.add.submit);
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].args, [3, { domain: "old.example.com", type: "redirect", redirect_to: "https://example.org", redirect_status: 302 }]);
});

test("add: going back to alias drops the redirect fields from the request", async () => {
  mountAdd();
  type("domain", "a.example.com");
  await choose(combos()[0], d.type.redirect);
  type("redirect_to", "https://example.org");
  await choose(combos()[0], d.type.alias);
  assert.equal(field("redirect_to"), null);
  submit(d.add.submit);
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].args, [3, { domain: "a.example.com", type: "alias" }]);
});

test("add: the DNS note shows the server IP when known", () => {
  mountAdd({ serverIp: "203.0.113.9" });
  assert.ok(screen.getByText(d.add.dnsNoteIp));
  assert.ok(screen.getByText("203.0.113.9"));
  cleanup();
  mountAdd();
  assert.ok(screen.getByText(d.add.dnsNote));
});

test("add: an active Let's Encrypt certificate warns, and the toast says to reissue", async () => {
  const certificate = { status: "active", type: "letsencrypt", type_title: "Let's Encrypt", force_https: true };
  mountAdd({ certificate });
  assert.ok(screen.getByText(d.add.dnsNoteSecured));
  assert.ok(screen.getByText(d.add.certNotice.replace("{current}", "Let's Encrypt")));
  assert.ok(screen.getByText(d.add.certNoticeForceHttps));
  type("domain", "new.example.com");
  submit(d.add.submit);
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(globalThis.__toastDescriptions, [d.toast.addedNeedsReissue.replace("{domain}", "new.example.com")]);
});

test("add: an uploaded certificate says it can't be reissued", async () => {
  mountAdd({ certificate: { status: "active", type: "custom", type_title: "Custom", force_https: false } });
  assert.ok(screen.getByText(d.add.certUploadedNotice));
  assert.equal(screen.queryByText(d.add.certNoticeForceHttps), null);
  type("domain", "new.example.com");
  submit(d.add.submit);
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(globalThis.__toastDescriptions, [d.toast.addedNeedsUpload.replace("{domain}", "new.example.com")]);
});

test("add: a certificate that isn't serving yet gives no warning", async () => {
  mountAdd({ certificate: { status: "pending", type: "letsencrypt", type_title: "Let's Encrypt" } });
  assert.equal(screen.queryByText(/won't cover the new name/), null);
  assert.ok(screen.getByText(d.add.dnsNote));
  type("domain", "new.example.com");
  submit(d.add.submit);
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(globalThis.__toastDescriptions, []);
});

test("add: a taken name shows under the field and keeps the dialog open", async () => {
  answer = (sent) => {
    throw serverError(sent, 422, { message: "Invalid.", errors: { domain: ["This domain is already in use."] } });
  };
  mountAdd();
  type("domain", "taken.example.com");
  submit(d.add.submit);
  const message = await screen.findByText("This domain is already in use.");
  assert.ok(message.closest("[data-slot=form-item]")?.querySelector('[name="domain"]'));
  assert.deepEqual(closes, []);
});

test("add: a server failure shows the add fallback", async () => {
  answer = () => {
    throw { response: { status: 500, data: {} } };
  };
  mountAdd();
  type("domain", "x.example.com");
  submit(d.add.submit);
  await waitFor(() => assert.equal(globalThis.__toasts.length, 1));
  assert.deepEqual(globalThis.__toasts[0], { kind: "error", message: d.toast.addFailed });
  assert.deepEqual(closes, []);
});

test("add: Cancel closes, and reopening starts empty again", async () => {
  const view = mountAdd();
  type("domain", "half.example.com");
  await choose(combos()[0], d.type.redirect);
  fireEvent.click(screen.getByRole("button", { name: d.cancel }));
  assert.deepEqual(closes, [false]);
  view.rerender({ open: false });
  view.rerender({ open: true });
  await waitFor(() => assert.equal(field("domain").value, ""));
  assert.equal(field("redirect_to"), null);
});

// ---------- Edit domain ----------

test("edit: shows the name locked, with the domain's own type", () => {
  mountEdit();
  assert.ok(screen.getByRole("dialog", { name: d.edit.title }));
  assert.ok(screen.getByText(d.edit.domainLocked));
  assert.match(document.querySelector("[role=dialog]").textContent, /www\.example\.com/);
  assert.equal(field("domain"), null, "the name is not editable");
  assert.match(combos()[0].textContent, /Alias/);
  assert.equal(field("redirect_to"), null);
});

test("edit: a redirect opens with its target and status", async () => {
  mountEdit({ domain: REDIRECT });
  await waitFor(() => assert.equal(field("redirect_to")?.value, "https://example.com"));
  assert.match(combos()[0].textContent, /Redirect/);
  assert.match(combos()[1].textContent, /307/);
});

test("edit: saving an alias sends only the type to that domain, then closes", async () => {
  mountEdit();
  submit(d.edit.submit);
  await waitFor(() => assert.deepEqual(closes, [false]));
  assert.deepEqual(calls, [{ name: "updateDomain", args: [3, "www.example.com", { type: "alias" }] }]);
  assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: d.toast.updated.replace("{domain}", "www.example.com") }]);
});

test("edit: turning a redirect into an alias warns that the target goes", async () => {
  mountEdit({ domain: REDIRECT });
  await waitFor(() => assert.ok(field("redirect_to")));
  assert.equal(screen.queryByText(/clears the redirect target/), null);
  await choose(combos()[0], d.type.alias);
  assert.ok(await screen.findByText(d.edit.clearsTarget.replace("{target}", "https://example.com")));
  submit(d.edit.submit);
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].args, [3, "old.example.com", { type: "alias" }]);
});

test("edit: a redirect target is checked and sent with its status", async () => {
  mountEdit({ domain: REDIRECT });
  await waitFor(() => assert.ok(field("redirect_to")));
  type("redirect_to", "");
  submit(d.edit.submit);
  assert.ok(await screen.findByText(v.redirectTargetRequired));
  type("redirect_to", "ftp://example.net");
  submit(d.edit.submit);
  assert.ok(await screen.findByText(v.redirectTargetUrl));
  assert.equal(calls.length, 0);
  type("redirect_to", " https://example.net ");
  await choose(combos()[1], d.add.redirectStatusOption["308"]);
  submit(d.edit.submit);
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].args, [3, "old.example.com", { type: "redirect", redirect_to: "https://example.net", redirect_status: 308 }]);
});

test("edit: an alias can become a redirect", async () => {
  mountEdit();
  await choose(combos()[0], d.type.redirect);
  type("redirect_to", "https://example.org");
  submit(d.edit.submit);
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].args, [3, "www.example.com", { type: "redirect", redirect_to: "https://example.org", redirect_status: 301 }]);
  assert.equal(screen.queryByText(/clears the redirect target/), null);
});

test("edit: a field error lands under the target, a failure shows the edit fallback", async () => {
  answer = (sent) => {
    throw serverError(sent, 422, { message: "Invalid.", errors: { redirect_to: ["That address loops back here."] } });
  };
  mountEdit({ domain: REDIRECT });
  await waitFor(() => assert.ok(field("redirect_to")));
  submit(d.edit.submit);
  const message = await screen.findByText("That address loops back here.");
  assert.ok(message.closest("[data-slot=form-item]")?.querySelector('[name="redirect_to"]'));
  cleanup();
  answer = () => {
    throw { response: { status: 500, data: {} } };
  };
  mountEdit();
  submit(d.edit.submit);
  await waitFor(() => assert.equal(globalThis.__toasts.length, 1));
  assert.deepEqual(globalThis.__toasts[0], { kind: "error", message: d.toast.updateFailed });
  assert.deepEqual(closes, []);
});

test("edit: opening for another domain shows that domain's values, typed changes are dropped", async () => {
  const view = mountEdit({ domain: REDIRECT });
  await waitFor(() => assert.ok(field("redirect_to")));
  type("redirect_to", "https://typed.example");
  fireEvent.click(screen.getByRole("button", { name: d.cancel }));
  assert.deepEqual(closes, [false]);
  view.rerender({ open: false });
  view.rerender({ open: true, domain: ALIAS });
  await waitFor(() => assert.equal(field("redirect_to"), null));
  assert.match(document.querySelector("[role=dialog]").textContent, /www\.example\.com/);
  view.rerender({ open: false });
  view.rerender({ open: true, domain: REDIRECT });
  await waitFor(() => assert.equal(field("redirect_to")?.value, "https://example.com"));
});

test("edit: a new domain passed while open replaces the shown values", async () => {
  const view = mountEdit({ domain: ALIAS });
  assert.equal(field("redirect_to"), null);
  view.rerender({ domain: REDIRECT });
  await waitFor(() => assert.equal(field("redirect_to")?.value, "https://example.com"));
});
