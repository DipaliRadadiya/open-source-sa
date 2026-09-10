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

/*
 * Parity with SaveWorkerRequest.
 *
 * Every rule below is the API's, quoted from
 * backend/app/Http/Requests/Server/Application/SaveWorkerRequest.php. The
 * numbers are asserted at the boundary rather than copied loosely: an off-by-one
 * here is a 422 the form promised would not happen.
 *
 * Six of the seven were the form being LOOSER than the server, which is the
 * expensive direction — the user types something the form accepts, presses
 * Save, and the server refuses it. One was the form being stricter, which is
 * quieter and worse: a value the server would have taken, refused with no way
 * to tell that the panel invented the limit.
 */
const base = { ...WORKER_FORM_DEFAULTS, name: "queue", command: "php artisan queue:work" };
const codes = (values) => {
  const result = workerFormSchema.safeParse(values);
  return result.success ? [] : result.error.issues.map((issue) => issue.message);
};

test("name stops at 60, where the API stops", () => {
  // 'name' => ['required', 'string', 'max:60', ...]
  assert.deepEqual(codes({ ...base, name: "n".repeat(60) }), []);
  assert.ok(codes({ ...base, name: "n".repeat(61) }).includes("max60"));
});

test("command stops at 500, where the API stops", () => {
  // 'command' => ['required', 'string', 'max:500', ...]
  assert.deepEqual(codes({ ...base, command: "c".repeat(500) }), []);
  assert.ok(codes({ ...base, command: "c".repeat(501) }).includes("max500"));
});

test("a command may not contain parentheses", () => {
  /*
   * The API's regex is /^[^\n\r;|&`$<>()]+$/ and the form's list was missing
   * both brackets — so `php artisan queue:work $(cat /tmp/x)` sailed through
   * the form and came back a 422. `$` alone would have caught that one; a bare
   * `(` would not.
   */
  for (const command of ["run (thing)", "php artisan x(y", "php artisan x)y"]) {
    assert.ok(codes({ ...base, command }).includes("shellMetacharacters"), command);
  }
  assert.deepEqual(codes({ ...base, command: "php artisan queue:work --tries=3" }), []);
});

test("the error names every character the API refuses, and the hint names none", () => {
  /*
   * The hint used to enumerate them — seven of the nine, which is how it went
   * stale unnoticed: a partial list reads as a complete one. It now states the
   * rule, and the error carries the characters, so there is exactly one place
   * to keep in step with the backend regex.
   */
  const messages = JSON.parse(readFileSync(new URL("../messages/en.json", import.meta.url), "utf8"));
  const error = messages.validation.shellMetacharacters;
  for (const char of ["|", ";", "&", "`", "$", "<", ">", "(", ")"]) {
    assert.ok(error.includes(char), `the error never mentions ${char}`);
  }
  // Both said the same sentence, stacked, when the error fired.
  assert.ok(!messages.applications.workers.form.commandHint.includes("|"));
});

test("a working directory is a path inside the site, on one line", () => {
  // 'directory' => [..., 'max:255', 'not_regex:/\.\./', new SingleLine]
  assert.deepEqual(codes({ ...base, directory: "/srv/app/".padEnd(255, "x") }), []);
  assert.ok(codes({ ...base, directory: "x".repeat(256) }).includes("max255"));
  assert.ok(codes({ ...base, directory: "/srv/app/../../etc" }).includes("noTraversal"));
  assert.ok(codes({ ...base, directory: "/srv/app\nuser=root" }).includes("noLineBreaks"));
});

test("stop timeout reaches 600 — the form was the strict one here", () => {
  /*
   * 'stop_wait_seconds' => [..., 'max:600'].
   *
   * The only rule of the seven where the panel refused something the server
   * accepts. A worker draining a long job could be given 480s through the API
   * and then never saved again from this form, with an error that named a
   * limit the server does not have.
   */
  assert.deepEqual(codes({ ...base, stop_wait_seconds: 600 }), []);
  assert.ok(codes({ ...base, stop_wait_seconds: 601 }).includes("max600"));
});

test("extra config may not open a second program block", () => {
  /*
   * 'extra_config' => [..., 'not_regex:/\[/'].
   *
   * These lines are appended verbatim inside this worker's block. A `[` starts
   * a new supervisord section — a program the panel did not write, cannot see
   * on this screen, and would never stop.
   */
  assert.ok(
    codes({ ...base, extra_config: "[program:secret]\ncommand=/bin/sh" }).includes("noSectionHeader"),
  );
  assert.deepEqual(codes({ ...base, extra_config: 'environment=APP_ENV="production"' }), []);
});

test("a log file is an absolute path, with no traversal and no second line", () => {
  // 'log_file' => [..., 'starts_with:/', 'not_regex:/\.\./', new SingleLine]
  assert.ok(codes({ ...base, log_file: "/var/log/../../etc/passwd" }).includes("noTraversalPath"));
  assert.ok(codes({ ...base, log_file: "/var/log/q.log\nuser=root" }).includes("noLineBreaks"));
  assert.deepEqual(codes({ ...base, log_file: "/var/log/q.log" }), []);
});

test("the folder message and the file message stay separate", () => {
  /*
   * `noTraversal` reads "Folders cannot contain .." and is shared with the web
   * root and open_basedir fields. A log file is not a folder, so it carries its
   * own key rather than quietly rewording theirs.
   */
  const messages = JSON.parse(readFileSync(new URL("../messages/en.json", import.meta.url), "utf8"));
  assert.equal(messages.validation.noTraversal, "Folders cannot contain ..");
  assert.ok(messages.validation.noTraversalPath);
});

test("every new validation code resolves in every locale", () => {
  // A code with no message renders as the raw string — `max60` in place of a
  // sentence. Dynamic keys evade the i18n gate, so they are checked here.
  for (const locale of ["en", "es", "hi"]) {
    const messages = JSON.parse(
      readFileSync(new URL(`../messages/${locale}.json`, import.meta.url), "utf8"),
    );
    for (const code of ["max60", "max600", "max500", "max255", "noTraversal", "noTraversalPath", "noSectionHeader", "noLineBreaks", "shellMetacharacters"]) {
      assert.ok(messages.validation[code], `${locale} has no message for ${code}`);
    }
    // Orphaned when stop_wait_seconds moved to 600; nothing else ever used it.
    assert.equal(messages.validation.max300, undefined, `${locale} still carries max300`);
  }
});

/*
 * Kind as a control.
 *
 * It was only ever set as a side effect of picking a template, so a Custom
 * worker could not become a Queue worker without being deleted and recreated —
 * while `SaveWorkerRequest` had accepted `kind` on update all along.
 */
test("both dialogs offer the kind, and exclude the right workers from the check", async () => {
  const { conflictingKind, WORKER_KINDS } = await import("../lib/applications/worker-kind.js");
  assert.deepEqual(WORKER_KINDS, ["queue", "horizon", "custom"]);

  const queue = [{ id: 1, kind: "queue" }];
  assert.equal(conflictingKind("horizon", queue), "queue");
  assert.equal(conflictingKind("queue", [{ id: 1, kind: "horizon" }]), "horizon");
  // Custom coexists with anything, and an empty site blocks nothing.
  assert.equal(conflictingKind("custom", queue), null);
  assert.equal(conflictingKind("queue", []), null);
  // A queue worker does not block ITSELF from becoming Horizon — the caller
  // filters it out, which is the one edit that is always safe.
  assert.equal(conflictingKind("horizon", []), null);

  const edit = readFileSync(
    new URL("../components/applications/workers/edit-worker-dialog.jsx", import.meta.url),
    "utf8",
  );
  assert.match(edit, /workers\.filter\(\(w\) => w\.id !== worker\.id\)/, "edit counts the worker against itself");
  assert.match(edit, /<WorkerKindField form=\{form\} workers=\{others\} \/>/);

  const create = readFileSync(
    new URL("../components/applications/workers/create-worker-dialog.jsx", import.meta.url),
    "utf8",
  );
  assert.match(create, /<WorkerKindField form=\{form\} workers=\{workers\} \/>/);
});

test("the conflict rule has exactly one copy", () => {
  // The select and the template menu both write `kind`. Two copies is how one
  // greys out an option the other still offers.
  const field = readFileSync(
    new URL("../components/applications/workers/worker-command-field.jsx", import.meta.url),
    "utf8",
  );
  assert.match(field, /from "@\/lib\/applications\/worker-kind"/);
  assert.doesNotMatch(field, /MUTUALLY_EXCLUSIVE_KIND\s*=/, "the menu still has its own copy");
});

test("every kind label resolves in every locale", () => {
  // `t(`form.kinds.${kind}`)` is built from a template literal, so the i18n
  // gate cannot see these three at all.
  for (const locale of ["en", "es", "hi"]) {
    const form = JSON.parse(
      readFileSync(new URL(`../messages/${locale}.json`, import.meta.url), "utf8"),
    ).applications.workers.form;
    assert.ok(form.kind, `${locale} has no kind label`);
    assert.ok(form.kindHint, `${locale} has no kind hint`);
    for (const kind of ["queue", "horizon", "custom"]) {
      assert.ok(form.kinds?.[kind], `${locale} has no label for ${kind}`);
    }
    // The blocked option borrows the menu's existing reason rather than a new one.
    assert.ok(form.conflict?.queue && form.conflict?.horizon, `${locale} lost a conflict reason`);
  }
});

test("the kind a worker already has is never the one it cannot keep", () => {
  /*
   * Server Sync can adopt a queue worker and a Horizon worker on one site — a
   * pair the API would have refused if the panel had created them. Blocking
   * the option that is already selected would then make "leave this alone" the
   * one thing the form will not do.
   */
  const field = readFileSync(
    new URL("../components/applications/workers/worker-kind-field.jsx", import.meta.url),
    "utf8",
  );
  assert.match(field, /kind === field\.value \? null : conflictingKind\(/);
});

test("a blocked kind states its reason in the row, not in a tooltip on it", () => {
  /*
   * A disabled SelectItem carries `data-disabled:pointer-events-none`, so a
   * ReasonTooltip wrapped around it never fires — hover, focus and touch all
   * pass through. That shape satisfies the disabled-reasons gate and still
   * shows the user nothing; it was only caught by driving the control.
   */
  const field = readFileSync(
    new URL("../components/applications/workers/worker-kind-field.jsx", import.meta.url),
    "utf8",
  );
  assert.doesNotMatch(field, /ReasonTooltip/, "a tooltip on a disabled option cannot open");
  assert.match(field, /\{disabledReason\}/, "the reason is not rendered anywhere");
});
