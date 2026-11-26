import assert from "node:assert/strict";
import { afterEach, before, beforeEach, test } from "node:test";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { IntlProvider } = await import("use-intl");
const { act, cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const messages = JSON.parse(readFileSync("messages/en.json", "utf8"));
const w = messages.applications.workers;

let CreateWorkerDialog, EditWorkerDialog, TooltipProvider, WorkerSiteProvider;
let calls, closes, answer;

before(async () => {
  const stubs = {
    "@/lib/api/workers": "tests/support/workers-api-stub.js",
    "@/hooks/use-refresh": "tests/support/use-refresh-stub.js",
    sonner: "tests/support/sonner-stub.js",
  };
  ({ CreateWorkerDialog } = await loadComponent("components/applications/workers/create-worker-dialog.jsx", stubs));
  ({ EditWorkerDialog } = await loadComponent("components/applications/workers/edit-worker-dialog.jsx", stubs));
  ({ WorkerSiteProvider } = await loadComponent("components/applications/workers/worker-site-context.js"));
  ({ TooltipProvider } = await loadComponent("components/ui/tooltip.jsx"));
});

beforeEach(() => {
  calls = [];
  closes = [];
  answer = () => ({ status: 201, data: {} });
  globalThis.__toasts = [];
  globalThis.__toastInfo = [];
  globalThis.__workersApi = {
    createWorker: async (...args) => {
      calls.push({ name: "createWorker", args });
      return answer(args[1]);
    },
    updateWorker: async (...args) => {
      calls.push({ name: "updateWorker", args });
      return answer(args[2]);
    },
  };
});
afterEach(() => cleanup());

const PRESETS = [
  { key: "queue", kind: "queue", title: "Laravel queue", command: "php artisan queue:work" },
  { key: "horizon", kind: "horizon", title: "Laravel Horizon", command: "php artisan horizon" },
  { key: "custom", kind: "custom", title: "Custom", command: "" },
];
const EXISTING = {
  id: 9,
  name: "jobs",
  command: "php artisan queue:work --tries=3",
  kind: "queue",
  processes: 2,
  directory: "",
  stop_wait_seconds: 30,
  user: null,
  effective_user: "siteuser",
  log_file: "",
  log_level: "",
  extra_config: "",
  auto_start: true,
  auto_restart: true,
  restart_on_deploy: false,
  enabled: true,
};

function wrap(child) {
  return createElement(
    IntlProvider,
    { locale: "en", messages, timeZone: "UTC" },
    createElement(TooltipProvider, null, createElement(WorkerSiteProvider, { value: { appRoot: "/home/siteuser/site" } }, child)),
  );
}

function mountCreate(props = {}) {
  const base = { open: true, onOpenChange: (next) => closes.push(next), appId: 4, presets: PRESETS, workers: [], siteUser: "siteuser" };
  return render(wrap(createElement(CreateWorkerDialog, { ...base, ...props })));
}

function mountEdit(props = {}) {
  const base = { open: true, onOpenChange: (next) => closes.push(next), appId: 4, presets: PRESETS, worker: EXISTING, workers: [EXISTING] };
  return render(wrap(createElement(EditWorkerDialog, { ...base, ...props })));
}

const field = (name) => document.querySelector(`[role=dialog] [name="${name}"]`);
const type = (name, value) => fireEvent.change(field(name), { target: { value } });
const submit = (label) => fireEvent.click(screen.getByRole("button", { name: label }));

// ---------- Add a worker ----------

test("create: opens empty with the add title, one process and the add button", () => {
  mountCreate();
  assert.ok(screen.getByRole("dialog", { name: w.create.title }));
  assert.equal(field("name").value, "");
  assert.equal(field("command").value, "");
  assert.equal(field("processes").value, "1");
  assert.ok(screen.getByRole("button", { name: w.create.submit }));
});

test("create: an empty form is refused before any request", async () => {
  mountCreate();
  submit(w.create.submit);
  await waitFor(() => assert.ok(document.querySelectorAll("[role=dialog] [data-slot=form-message]").length >= 2));
  assert.equal(calls.length, 0);
});

test("create: a valid form sends trimmed values, never a user, and closes with a toast", async () => {
  mountCreate();
  type("name", "  mailer  ");
  type("command", "  php artisan queue:work  ");
  submit(w.create.submit);
  await waitFor(() => assert.equal(calls.length, 1));
  const [appId, payload] = calls[0].args;
  assert.equal(calls[0].name, "createWorker");
  assert.equal(appId, 4);
  assert.equal(payload.name, "mailer");
  assert.equal(payload.command, "php artisan queue:work");
  assert.equal(payload.user, undefined);
  assert.equal(payload.directory, undefined);
  assert.equal(payload.processes, 1);
  await waitFor(() => assert.deepEqual(closes, [false]));
  assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: w.toast.created }]);
});

test("create: a 202 (supervisor being installed) keeps the dialog open and says so", async () => {
  answer = () => ({ status: 202, data: { message: "Installing supervisor first." } });
  mountCreate();
  type("name", "mailer");
  type("command", "php artisan queue:work");
  submit(w.create.submit);
  await screen.findByText("Installing supervisor first.");
  assert.deepEqual(closes, []);
});

test("create: a template from the seed fills name, command and kind", async () => {
  mountCreate({ seed: "horizon" });
  await waitFor(() => assert.equal(field("command").value, "php artisan horizon"));
  assert.equal(field("name").value, "Laravel Horizon");
});

test("create: picking a template fills an empty name, but never overwrites a typed one", async () => {
  mountCreate();
  const pick = async (title) => {
    const trigger = screen.getByRole("button", { name: w.form.useTemplate });
    act(() => trigger.focus());
    fireEvent.keyDown(trigger, { key: "Enter" });
    fireEvent.click(await screen.findByRole("menuitem", { name: new RegExp(title) }));
  };
  await pick("Laravel queue");
  await waitFor(() => assert.equal(field("command").value, "php artisan queue:work"));
  assert.equal(field("name").value, "Laravel queue");
  type("name", "my-name");
  await pick("Laravel Horizon");
  await waitFor(() => assert.equal(field("command").value, "php artisan horizon"));
  assert.equal(field("name").value, "my-name");
});

test("create: Horizon is refused while a queue worker exists", async () => {
  mountCreate({ workers: [EXISTING] });
  const trigger = screen.getByRole("button", { name: w.form.useTemplate });
  act(() => trigger.focus());
  fireEvent.keyDown(trigger, { key: "Enter" });
  const horizon = await screen.findByRole("menuitem", { name: /Laravel Horizon/ });
  assert.equal(horizon.getAttribute("aria-disabled"), "true");
});

test("create: a 500 without field errors shows the API's sentence in the dialog", async () => {
  answer = () => {
    throw { response: { status: 500, data: { message: "Supervisor refused the config." } } };
  };
  mountCreate();
  type("name", "mailer");
  type("command", "php artisan queue:work");
  submit(w.create.submit);
  const alert = await screen.findByRole("alert");
  assert.match(alert.textContent, /Supervisor refused the config\./);
  assert.deepEqual(closes, []);
});

test("create: a 422 field error lands under its field", async () => {
  answer = (sent) => {
    throw { config: { data: JSON.stringify(sent) }, response: { status: 422, data: { message: "Invalid.", errors: { command: ["The command is not allowed."] } } } };
  };
  mountCreate();
  type("name", "mailer");
  type("command", "php artisan queue:work");
  submit(w.create.submit);
  const message = await screen.findByText("The command is not allowed.");
  assert.ok(message.closest("[data-slot=form-item]")?.querySelector('[name="command"]'), "shown under the Command field");
  assert.deepEqual(closes, []);
});

test("create: a worker that did not start shows the add-specific message", async () => {
  answer = (sent) => {
    throw {
      config: { data: JSON.stringify(sent) },
      response: {
        status: 422,
        data: { message: "exited with code 1", reason: "worker_did_not_start", errors: { command: ["It exited at once."] } },
      },
    };
  };
  mountCreate();
  type("name", "mailer");
  type("command", "php artisan queue:work");
  submit(w.create.submit);
  const fieldError = await screen.findByText("It exited at once.");
  assert.ok(fieldError.closest("[data-slot=form-item]")?.querySelector('[name="command"]'), "shown under the Command field");
  assert.match((await screen.findByRole("alert")).textContent, /exited with code 1/);
});

test("create: Cancel closes, and reopening starts empty again", async () => {
  const { rerender } = mountCreate();
  type("name", "half-typed");
  fireEvent.click(screen.getByRole("button", { name: w.cancel }));
  assert.deepEqual(closes, [false]);
  rerender(wrap(createElement(CreateWorkerDialog, { open: false, onOpenChange: () => {}, appId: 4, presets: PRESETS, workers: [] })));
  rerender(wrap(createElement(CreateWorkerDialog, { open: true, onOpenChange: () => {}, appId: 4, presets: PRESETS, workers: [] })));
  await waitFor(() => assert.equal(field("name").value, ""));
});

// ---------- Edit worker ----------

test("edit: opens with the worker's own values and the edit title", () => {
  mountEdit();
  assert.ok(screen.getByRole("dialog", { name: w.edit.title }));
  assert.equal(field("name").value, "jobs");
  assert.equal(field("command").value, "php artisan queue:work --tries=3");
  assert.equal(field("processes").value, "2");
  assert.ok(screen.getByRole("button", { name: w.edit.submit }));
});

test("edit: saving sends the worker id and closes with the updated toast", async () => {
  mountEdit();
  type("command", " php artisan queue:work --tries=5 ");
  submit(w.edit.submit);
  await waitFor(() => assert.equal(calls.length, 1));
  assert.equal(calls[0].name, "updateWorker");
  const [appId, id, payload] = calls[0].args;
  assert.equal(appId, 4);
  assert.equal(id, 9);
  assert.equal(payload.command, "php artisan queue:work --tries=5");
  assert.equal(payload.user, undefined);
  await waitFor(() => assert.deepEqual(closes, [false]));
  assert.deepEqual(globalThis.__toasts, [{ kind: "success", message: w.toast.updated }]);
});

test("edit: picking a template never renames the worker", async () => {
  mountEdit({ worker: { ...EXISTING, kind: "custom" }, workers: [] });
  const trigger = screen.getByRole("button", { name: w.form.useTemplate });
  act(() => trigger.focus());
  fireEvent.keyDown(trigger, { key: "Enter" });
  fireEvent.click(await screen.findByRole("menuitem", { name: /Laravel Horizon/ }));
  await waitFor(() => assert.equal(field("command").value, "php artisan horizon"));
  assert.equal(field("name").value, "jobs");
});

test("edit: the worker itself never blocks its own kind", async () => {
  // The only queue worker is this one, so switching it to Horizon must be allowed.
  mountEdit();
  const trigger = screen.getByRole("button", { name: w.form.useTemplate });
  act(() => trigger.focus());
  fireEvent.keyDown(trigger, { key: "Enter" });
  const horizon = await screen.findByRole("menuitem", { name: /Laravel Horizon/ });
  assert.notEqual(horizon.getAttribute("aria-disabled"), "true");
});

test("edit: a 500 shows the edit-specific failure in the dialog", async () => {
  answer = () => {
    throw { response: { status: 500, data: {} } };
  };
  mountEdit();
  submit(w.edit.submit);
  const alert = await screen.findByRole("alert");
  assert.match(alert.textContent, new RegExp(w.edit.failed.slice(0, 30).replace(/[.*+?^${}()|[\]\\]/g, "\\$&")));
  assert.deepEqual(closes, []);
});

test("edit: Cancel throws away typed changes", async () => {
  const { rerender } = mountEdit();
  type("name", "renamed");
  fireEvent.click(screen.getByRole("button", { name: w.cancel }));
  assert.deepEqual(closes, [false]);
  const props = { appId: 4, presets: PRESETS, worker: EXISTING, workers: [EXISTING], onOpenChange: () => {} };
  rerender(wrap(createElement(EditWorkerDialog, { ...props, open: false })));
  rerender(wrap(createElement(EditWorkerDialog, { ...props, open: true })));
  await waitFor(() => assert.equal(field("name").value, "jobs"));
});
