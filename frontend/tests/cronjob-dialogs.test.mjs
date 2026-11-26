import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { act, cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const c = messages.cronJobs;
const v = messages.validation;

let CreateCronjobDialog, EditCronjobDialog, TooltipProvider;
let calls, closes, answer;

before(async () => {
  const stubs = {
    "@/lib/api/cronjobs": "tests/support/cronjobs-api-stub.js",
    "@/hooks/use-refresh": "tests/support/use-refresh-stub.js",
    sonner: "tests/support/sonner-stub.js",
  };
  ({ CreateCronjobDialog } = await loadComponent("components/cron-jobs/create-cronjob-dialog.jsx", stubs));
  ({ EditCronjobDialog } = await loadComponent("components/cron-jobs/edit-cronjob-dialog.jsx", stubs));
  ({ TooltipProvider } = await loadComponent("components/ui/tooltip.jsx"));
});

beforeEach(() => {
  calls = [];
  closes = [];
  answer = () => ({ data: {} });
  globalThis.__toasts = [];
  globalThis.__plainRefreshes = 0;
  globalThis.__cronApi = {
    createCronjob: async (...args) => {
      calls.push({ name: "createCronjob", args });
      return answer(args[0]);
    },
    updateCronjob: async (...args) => {
      calls.push({ name: "updateCronjob", args });
      return answer(args[1]);
    },
  };
});
afterEach(() => cleanup());

const USERS = [
  { id: 2, username: "qa" },
  { id: 3, username: "deploy" },
];
const PRESETS = [{ key: "phpv", label: "PHP version", command: "php -v", expression: "*/5 * * * *" }];
const JOB = {
  id: 41,
  name: "Backup",
  username: "qa",
  system_user: { id: 2, username: "qa" },
  command: "/usr/bin/backup",
  expression: "0 2 * * *",
  active: true,
  application_id: null,
};

const wrap = (child) =>
  createElement(IntlProvider, { locale: "en", messages, timeZone: "UTC" }, createElement(TooltipProvider, null, child));

function mount(Dialog, base, props) {
  const all = { open: true, onOpenChange: (next) => closes.push(next), systemUsers: USERS, timezone: "UTC", ...base, ...props };
  const view = render(wrap(createElement(Dialog, all)));
  return { ...view, rerender: (p) => view.rerender(wrap(createElement(Dialog, { ...all, ...p }))) };
}
const mountCreate = (props = {}) => mount(CreateCronjobDialog, {}, props);
const mountEdit = (props = {}) => mount(EditCronjobDialog, { job: JOB }, props);

const field = (name) => document.querySelector(`[role=dialog] [name="${name}"]`);
const type = (name, value) => fireEvent.change(field(name), { target: { value } });
const button = (name) => screen.getByRole("button", { name });
const runAs = () => screen.getAllByRole("combobox")[0];
const errors = () => [...document.querySelectorAll("[role=dialog] [data-slot=form-message]")].map((e) => e.textContent);

async function choose(combo, optionName) {
  act(() => combo.focus());
  fireEvent.keyDown(combo, { key: "Enter" });
  fireEvent.click(await screen.findByRole("option", { name: optionName }));
}

async function fillValid() {
  type("name", "  Nightly backup ");
  await choose(runAs(), "qa");
  type("command", "  /usr/bin/backup --all ");
  type("expression", " 0 3 * * * ");
}

const serverError = (sent, status, data) => ({ config: { data: JSON.stringify(sent) }, response: { status, data } });

// ---------- Create ----------

test("create: opens empty and active, with the add title", () => {
  mountCreate();
  assert.ok(screen.getByRole("dialog", { name: c.create.title }));
  for (const name of ["name", "command", "expression"]) assert.equal(field(name).value, "", name);
  assert.equal(screen.getByRole("switch").getAttribute("aria-checked"), "true");
  assert.equal(field("username"), null);
});

test("create: an empty form names every missing field and sends nothing", async () => {
  mountCreate();
  fireEvent.click(button(c.create.submit));
  await waitFor(() => assert.ok(errors().length >= 4, errors().join(" | ")));
  for (const key of ["required_name", "required_runAs", "required_command", "required_expression"]) {
    assert.ok(errors().includes(v[key]), key);
  }
  assert.equal(calls.length, 0);
});

test("create: a valid job is sent trimmed with the picked system user, then closes", async () => {
  mountCreate();
  await fillValid();
  fireEvent.click(button(c.create.submit));
  await waitFor(() => assert.deepEqual(closes, [false]));
  assert.deepEqual(calls, [{
    name: "createCronjob",
    args: [{ name: "Nightly backup", command: "/usr/bin/backup --all", expression: "0 3 * * *", active: true, system_user_id: 2 }],
  }]);
  assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: c.toast.created }]);
});

test("create: another system user is typed, checked, and sent by name", async () => {
  mountCreate();
  await fillValid();
  await choose(runAs(), c.form.otherUser);
  assert.ok(field("username"));
  type("username", "root");
  fireEvent.click(button(c.create.submit));
  await waitFor(() => assert.ok(errors().includes(v.cronRootRefused)));
  type("username", "Bad User");
  fireEvent.click(button(c.create.submit));
  await waitFor(() => assert.ok(errors().includes(v.linuxUsername)));
  assert.equal(calls.length, 0);
  type("username", " www-data ");
  fireEvent.click(screen.getByRole("switch"));
  fireEvent.click(button(c.create.submit));
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].args[0], { name: "Nightly backup", command: "/usr/bin/backup --all", expression: "0 3 * * *", active: false, username: "www-data" });
});

test("create: schedule, name and command rules apply before sending", async () => {
  mountCreate();
  await fillValid();
  type("expression", "every day");
  fireEvent.click(button(c.create.submit));
  await waitFor(() => assert.ok(errors().includes(v.cronExpression)));
  type("expression", "@reboot");
  fireEvent.click(button(c.create.submit));
  await waitFor(() => assert.ok(errors().includes(v.cronReboot)));
  type("expression", "@daily");
  type("name", "certbot");
  fireEvent.click(button(c.create.submit));
  await waitFor(() => assert.ok(errors().includes(v.cronNameReserved)));
  assert.equal(calls.length, 0);
});

test("create: a duplicated job opens filled in and keeps its site", async () => {
  const initialValues = { name: "Backup (copy)", run_as: "3", command: "/usr/bin/backup", expression: "@daily", active: false, application_id: 7 };
  mountCreate({ initialValues });
  await waitFor(() => assert.equal(field("name").value, "Backup (copy)"));
  assert.match(runAs().textContent, /deploy/);
  fireEvent.click(button(c.create.submit));
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].args[0], { name: "Backup (copy)", command: "/usr/bin/backup", expression: "@daily", active: false, application_id: 7, system_user_id: 3 });
});

test("create: a quick start fills command and schedule", async () => {
  mountCreate({ commandPresets: PRESETS, starterKey: "phpv" });
  await waitFor(() => assert.equal(field("command").value, "php -v"));
  assert.equal(field("expression").value, "*/5 * * * *");
});

test("create: the account list failing says how to carry on", () => {
  mountCreate({ systemUsers: [], systemUsersFailed: true });
  assert.ok(screen.getByText(c.form.runAsUnavailable));
});

test("create: a server field error lands under the field; a failure shows the create fallback", async () => {
  answer = (sent) => {
    throw serverError(sent, 422, { message: "Invalid.", errors: { name: ["A job with this name already exists."] } });
  };
  mountCreate();
  await fillValid();
  fireEvent.click(button(c.create.submit));
  const message = await screen.findByText("A job with this name already exists.");
  assert.ok(message.closest("[data-slot=form-item]")?.querySelector('[name="name"]'));
  assert.deepEqual(closes, []);
  answer = () => {
    throw { response: { status: 500, data: {} } };
  };
  fireEvent.click(button(c.create.submit));
  await waitFor(() => assert.equal(globalThis.__toasts.length, 1));
  assert.deepEqual(globalThis.__toasts[0], { kind: "error", message: c.toast.createFailed });
  assert.deepEqual(closes, []);
});

test("create: Cancel closes, and reopening starts empty", async () => {
  const view = mountCreate();
  await fillValid();
  fireEvent.click(button(c.cancel));
  assert.deepEqual(closes, [false]);
  view.rerender({ open: false });
  view.rerender({ open: true });
  await waitFor(() => assert.equal(field("name").value, ""));
  assert.equal(field("command").value, "");
});

// ---------- Edit ----------

test("edit: opens with the job's values and Save waits for a change", async () => {
  mountEdit();
  assert.ok(screen.getByRole("dialog", { name: c.edit.title }));
  assert.equal(field("name").value, "Backup");
  assert.equal(field("command").value, "/usr/bin/backup");
  assert.equal(field("expression").value, "0 2 * * *");
  assert.match(runAs().textContent, /qa/);
  assert.equal(button(c.edit.submit).disabled, true);
  type("name", "Backup nightly");
  await waitFor(() => assert.equal(button(c.edit.submit).disabled, false));
});

test("edit: saving sends the fields but not an unchanged user or site", async () => {
  mountEdit();
  type("name", " Backup nightly ");
  await waitFor(() => assert.equal(button(c.edit.submit).disabled, false));
  fireEvent.click(button(c.edit.submit));
  await waitFor(() => assert.deepEqual(closes, [false]));
  assert.deepEqual(calls, [{ name: "updateCronjob", args: [41, { name: "Backup nightly", command: "/usr/bin/backup", expression: "0 2 * * *", active: true }] }]);
  assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: c.toast.updated }]);
});

test("edit: a new system user is sent by id", async () => {
  mountEdit();
  await choose(runAs(), "deploy");
  await waitFor(() => assert.equal(button(c.edit.submit).disabled, false));
  fireEvent.click(button(c.edit.submit));
  await waitFor(() => assert.equal(calls.length, 1));
  assert.equal(calls[0].args[1].system_user_id, 3);
  assert.equal("username" in calls[0].args[1], false);
});

test("edit: a job run by a typed user opens on Other and sends the new name", async () => {
  const job = { ...JOB, system_user: null, username: "www-data" };
  mountEdit({ job });
  await waitFor(() => assert.equal(field("username")?.value, "www-data"));
  type("username", "nginx");
  await waitFor(() => assert.equal(button(c.edit.submit).disabled, false));
  fireEvent.click(button(c.edit.submit));
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].args[1], { name: "Backup", command: "/usr/bin/backup", expression: "0 2 * * *", active: true, system_user_id: null, username: "nginx" });
});

test("edit: a site-linked job keeps its site unless it changes", async () => {
  mountEdit({ job: { ...JOB, application_id: 7 } });
  fireEvent.click(screen.getByRole("switch"));
  await waitFor(() => assert.equal(button(c.edit.submit).disabled, false));
  fireEvent.click(button(c.edit.submit));
  await waitFor(() => assert.equal(calls.length, 1));
  assert.deepEqual(calls[0].args[1], { name: "Backup", command: "/usr/bin/backup", expression: "0 2 * * *", active: false });
});

test("edit: a job removed elsewhere is reported as gone and the list re-read", async () => {
  answer = () => {
    throw { response: { status: 404, data: {} } };
  };
  mountEdit();
  type("name", "Backup 2");
  await waitFor(() => assert.equal(button(c.edit.submit).disabled, false));
  fireEvent.click(button(c.edit.submit));
  await waitFor(() => assert.deepEqual(closes, [false]));
  assert.deepEqual(globalThis.__toasts, [{ kind: "info", message: c.toast.alreadyGone.replace("{name}", "Backup") }]);
  assert.equal(globalThis.__plainRefreshes, 1);
});

test("edit: a field error lands under the field; a failure shows the edit fallback", async () => {
  answer = (sent) => {
    throw serverError(sent, 422, { message: "Invalid.", errors: { command: ["That binary does not exist."] } });
  };
  mountEdit();
  type("command", "/usr/bin/nope");
  await waitFor(() => assert.equal(button(c.edit.submit).disabled, false));
  fireEvent.click(button(c.edit.submit));
  const message = await screen.findByText("That binary does not exist.");
  assert.ok(message.closest("[data-slot=form-item]")?.querySelector('[name="command"]'));
  answer = () => {
    throw { response: { status: 500, data: { message: "crontab refused the file." } } };
  };
  fireEvent.click(button(c.edit.submit));
  await waitFor(() => assert.equal(globalThis.__toasts.length, 1));
  assert.deepEqual(globalThis.__toasts[0], { kind: "error", message: "crontab refused the file." });
  assert.deepEqual(closes, []);
});

test("edit: Cancel drops typed changes, and another job opens with its own values", async () => {
  const view = mountEdit();
  type("name", "typed");
  fireEvent.click(button(c.cancel));
  assert.deepEqual(closes, [false]);
  view.rerender({ open: false });
  view.rerender({ open: true });
  await waitFor(() => assert.equal(field("name").value, "Backup"));
  view.rerender({ open: false });
  view.rerender({ open: true, job: { ...JOB, id: 42, name: "Cleanup", command: "/usr/bin/clean", expression: "@hourly" } });
  await waitFor(() => assert.equal(field("name").value, "Cleanup"));
  assert.equal(field("expression").value, "@hourly");
});
