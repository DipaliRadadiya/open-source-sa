import { test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { hostProblem } from "../lib/schemas/database.js";
import { isValidApplicationDomain, securityFormSchema } from "../lib/schemas/application.js";
import { createCronjobSchema, OTHER_USER } from "../lib/schemas/cronjob.js";
import { workerFormSchemaFor, WORKER_FORM_DEFAULTS } from "../lib/schemas/worker.js";
import { botRuleError } from "../lib/schemas/bot-rule.js";
import { cleanLines } from "../lib/logs/clean-lines.js";
import { changePasswordSchema } from "../lib/schemas/account.js";
import { createStorageDestinationSchema, editStorageDestinationSchema } from "../lib/schemas/storage.js";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

// Bugs from the two lists Krishna sent on 30 Sep.

test("database host: real IPv4 and /0–32 only", () => {
  for (const bad of ["999.999.999.999", "1.2.3.4/99", "999.1.1.1", "1.2.3.4/33"]) assert.equal(hostProblem(bad), "databaseHost", bad);
  for (const good of ["10.0.0.1", "10.0.0.0/8", "0.0.0.0/0"]) assert.equal(hostProblem(good), null, good);
});

test("an IP address is not a domain", () => {
  for (const bad of ["127.0.0.1", "23.172.120.86", "-bad-.example.com", "qa..example.com", "localhost"]) assert.equal(isValidApplicationDomain(bad), false, bad);
  assert.equal(isValidApplicationDomain("1password.com"), true);
});

test("cron: panel file names reserved, root refused", () => {
  const base = { name: "x", run_as: "1", command: "ls", expression: "@daily", active: true };
  for (const name of ["panel-scheduler", "Panel Scheduler", "php"]) {
    assert.equal(createCronjobSchema.safeParse({ ...base, name }).error?.issues[0]?.message, "cronNameReserved", name);
  }
  assert.equal(createCronjobSchema.safeParse({ ...base, run_as: OTHER_USER, username: "root" }).error?.issues[0]?.message, "cronRootRefused");
  assert.equal(createCronjobSchema.safeParse({ ...base, run_as: OTHER_USER, username: "www-data" }).success, true);
});

test("worker: no user= in extra config, paths inside the application", () => {
  const schema = workerFormSchemaFor("/home/u/app");
  const base = { ...WORKER_FORM_DEFAULTS, name: "w", command: "php artisan queue:work" };
  assert.equal(schema.safeParse(base).success, true);
  assert.equal(schema.safeParse({ ...base, extra_config: "user=root" }).error?.issues[0]?.message, "noManagedWorkerKeys");
  assert.equal(schema.safeParse({ ...base, log_file: "/etc/x.log" }).error?.issues[0]?.message, "insideApplication");
  assert.equal(schema.safeParse({ ...base, directory: "/etc" }).error?.issues[0]?.message, "insideApplication");
  assert.equal(schema.safeParse({ ...base, log_file: "/home/u/app/logs/w.log" }).success, true);
});

test("bot blocker refuses words inside browser and search user agents", () => {
  for (const word of ["Chrome", "Safari", "Firefox", "Edg", "Windows", "Android", "AppleWebKit"]) assert.equal(botRuleError(word), "browserWord", word);
  assert.equal(botRuleError("Googlebot/2.1"), "searchEngine");
  for (const bot of ["GPTBot", "ClaudeBot", "CCBot", "Applebot-Extended"]) assert.equal(botRuleError(bot), null, bot);
});

test("log lines lose their terminal colour codes", () => {
  assert.deepEqual(cleanLines(["\u001b[36m2026\u001b[0m [\u001b[38;5;117mDB\u001b[0m] ok"]), ["2026 [DB] ok"]);
});

test("the new password must differ from the current one", () => {
  const r = changePasswordSchema.safeParse({ current_password: "Secret123!", password: "Secret123!", password_confirmation: "Secret123!" });
  assert.equal(r.error?.issues.some((issue) => issue.message === "passwordSameAsCurrent"), true);
});

test("storage folder refuses . and .. segments; edit needs an endpoint or a region, not both", () => {
  const add = createStorageDestinationSchema("aws");
  const cfg = { bucket: "b", region: "ap-south-1", access_key: "k", secret_key: "s" };
  assert.equal(add.safeParse({ name: "n", prefix: "../etc", config: cfg }).error?.issues[0]?.message, "prefixTraversal");
  const edit = editStorageDestinationSchema({ provider: "s3" });
  assert.equal(edit.safeParse({ name: "n", prefix: "", config: { bucket: "b", region: "ap-south-1", endpoint: "" } }).success, true);
  assert.equal(edit.safeParse({ name: "n", prefix: "", config: { bucket: "b", region: "", endpoint: "" } }).success, false);
});

test("password protection usernames are ASCII", () => {
  const r = securityFormSchema?.safeParse?.({ enabled: true, username: "ünïcode", password: "longenough" });
  if (r) assert.equal(r.error?.issues[0]?.message, "securityUsernameAscii");
  assert.match(read("lib/schemas/application.js"), /securityUsernameAscii/);
});

test("delete database names the application; connection card keeps the oldest user", () => {
  assert.match(read("components/databases/delete-database-dialog.jsx"), /t\("delete\.usedBy", \{ application: application\.name \}\)/);
  assert.match(read("lib/databases/connection-parts.js"), /\.sort\(\(a, b\) => a\.id - b\.id\)/);
});

test("icon-only outline buttons stay neutral", () => {
  const src = read("components/ui/button.jsx");
  assert.match(src, /size: \["icon", "icon-xs", "icon-sm", "icon-lg"\], className: NEUTRAL/);
});

test("Push to production starts on Files (Krishna, 30 Sep)", () => {
  assert.match(read("components/applications/staging/push-staging-dialog.jsx"), /useState\("files"\)/);
});
