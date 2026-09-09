import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { workerSchema, workerFormSchema, WORKER_FORM_DEFAULTS } from "../lib/schemas/worker.js";

test("the supervisord settings survive parsing", () => {
  /*
   * Workers moved from systemd units to supervisord programs and gained six
   * fields. None were declared, so Zod dropped all six: the panel could
   * neither show a worker's user nor keep one, though the API accepts them.
   */
  const parsed = workerSchema.parse({
    id: 1, name: "queue", command: "php artisan queue:work", kind: "queue",
    processes: 1, running: 1, state: "running", auto_restart: true,
    restart_on_deploy: true, enabled: true,
    user: "deploy", effective_user: "deploy",
    log_file: "/var/log/queue.log", log_level: "info",
    extra_config: 'environment=APP_ENV="production"', auto_start: true,
  });
  for (const key of ["user", "effective_user", "log_file", "log_level", "extra_config", "auto_start"]) {
    assert.ok(parsed[key] !== undefined, `${key} was stripped`);
  }
});

test("blank means 'no opinion', which is what the API assumes", () => {
  // Sending "" would ask the server to store an empty username and an empty
  // log path — a different request from not mentioning them.
  const values = workerFormSchema.parse({
    ...WORKER_FORM_DEFAULTS, name: "queue", command: "php artisan queue:work",
  });
  assert.equal(values.user, "");
  assert.equal(values.log_level, "");
  assert.equal(values.auto_start, true);

  const create = readFileSync(
    new URL("../components/applications/workers/create-worker-dialog.jsx", import.meta.url),
    "utf8",
  );
  assert.match(create, /user: values\.user\?\.trim\(\) \|\| undefined/);
  assert.match(create, /log_level: values\.log_level \|\| undefined/);
});

test("the form refuses what the API would refuse", () => {
  const base = { ...WORKER_FORM_DEFAULTS, name: "queue", command: "php artisan queue:work" };
  // Unix usernames only — the API's own regex.
  assert.throws(() => workerFormSchema.parse({ ...base, user: "Deploy User" }));
  // An absolute path, or supervisord writes somewhere nobody expects.
  assert.throws(() => workerFormSchema.parse({ ...base, log_file: "relative/path.log" }));
  // One of supervisord's seven levels.
  assert.throws(() => workerFormSchema.parse({ ...base, log_level: "verbose" }));
  // And the valid forms pass.
  assert.doesNotThrow(() => workerFormSchema.parse({ ...base, user: "deploy", log_file: "/var/log/q.log", log_level: "debug" }));
});

test("both dialogs share one set of advanced fields", () => {
  // They already held identical copies of the first two, and adding five more
  // to each by hand is how they start to disagree.
  for (const file of ["create-worker-dialog.jsx", "edit-worker-dialog.jsx"]) {
    const s = readFileSync(
      new URL(`../components/applications/workers/${file}`, import.meta.url),
      "utf8",
    );
    assert.match(s, /<WorkerAdvancedFields form=\{form\} \/>/, `${file} does not use the shared fields`);
  }
});

test("edit seeds from `user`, never the resolved fallback", () => {
  /*
   * `effective_user` is what a worker with no user of its own resolves to.
   * Seeding the form from it would save the site's username as if it had been
   * chosen deliberately, and the worker would stop following the site.
   */
  const edit = readFileSync(
    new URL("../components/applications/workers/edit-worker-dialog.jsx", import.meta.url),
    "utf8",
  );
  assert.match(edit, /user: worker\.user \?\? ""/);
  assert.doesNotMatch(edit, /user: worker\.effective_user/);
});
