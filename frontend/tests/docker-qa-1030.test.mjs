// Frontend fixes from the .115 Docker browser QA (30 Oct).
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

import { containerSettingsFormSchema, dockerNameSchema } from "../lib/schemas/docker.js";
import { breaksDockerLimit, dockerLimitRule } from "../lib/applications/docker-limit-rules.js";
import { confirmNotes, keepApplicable } from "../lib/disk-cleaner/confirm-notes.js";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");
const strip = (s) => s.replace(/\{\/\*[\s\S]*?\*\/\}/g, "").replace(/\/\/.*$/gm, "");
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];
const messages = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]));
const issues = (schema, value) => schema.safeParse(value).error?.issues.map((i) => i.message) ?? [];

function walk(dir) {
  return fs.readdirSync(path.join(root, dir), { withFileTypes: true }).flatMap((entry) => {
    const rel = path.join(dir, entry.name);
    if (entry.isDirectory()) return walk(rel);
    return /\.(jsx?|mjs)$/.test(entry.name) ? [rel] : [];
  });
}

test("Docker name, memory, CPU and port errors are translated keys, never Zod's English", () => {
  const base = { container_port: 80, registry_id: "", memory_limit: "", cpu_limit: "", docker_network: "" };
  const got = [
    ...issues(dockerNameSchema, "x$(id)"),
    ...issues(dockerNameSchema, "../x"),
    ...issues(dockerNameSchema, ""),
    ...issues(dockerNameSchema, "a".repeat(129)),
    ...issues(containerSettingsFormSchema, { ...base, memory_limit: "abc" }),
    ...issues(containerSettingsFormSchema, { ...base, cpu_limit: "1.234" }),
    ...issues(containerSettingsFormSchema, { ...base, container_port: "x" }),
    ...issues(containerSettingsFormSchema, { ...base, container_port: 70000 }),
  ];
  assert.deepEqual([...new Set(got)].sort(), ["dockerCpu", "dockerMemory", "dockerName", "dockerNameLength", "portRange", "requiredField"]);
  for (const locale of LOCALES) {
    for (const key of ["dockerCpu", "dockerMemory", "dockerName", "dockerNameLength", "portRange"]) {
      assert.ok(messages[locale].validation[key], `${locale}: validation.${key}`);
    }
  }
  assert.deepEqual(issues(dockerNameSchema, "qa-net_1.2"), []);
  assert.deepEqual(issues(containerSettingsFormSchema, { ...base, memory_limit: "512m", cpu_limit: "0.5" }), []);
  // The old swap compared against Zod 3's type name and never fired.
  assert.doesNotMatch(read("components/docker/docker-resources-panel.jsx"), /invalid_string/);
});

test("a failed save toasts once: nobody adds a second toast after handleValidationError", () => {
  const offenders = [...walk("components"), ...walk("app")].filter((file) => /if \(!handleValidationError\(/.test(read(file)));
  assert.deepEqual(offenders, []);
  for (const file of ["components/docker/docker-resources-panel.jsx", "components/applications/container-card.jsx", "components/php/php-common-settings.jsx"]) {
    assert.match(read(file), /handleValidationError\(error, \w+, \{ fallback: t\("failed"\) \}\);/, file);
  }
});

test("container limits: the create form's rule matches what the server takes", () => {
  for (const value of ["512m", "2g", "1024", "256M", ""]) assert.equal(breaksDockerLimit("memory_limit", value), false, value);
  for (const value of ["abc", "2gb", "-1", "1.5g"]) assert.equal(breaksDockerLimit("memory_limit", value), true, value);
  for (const value of ["1", "0.5", "2.25", ""]) assert.equal(breaksDockerLimit("cpu_limit", value), false, value);
  for (const value of ["1.234", "abc", "-1", "1,5"]) assert.equal(breaksDockerLimit("cpu_limit", value), true, value);
  assert.equal(breaksDockerLimit("name", "abc"), false, "only the two limit fields have a rule");
  assert.equal(dockerLimitRule("memory_limit").scope, "validation");
});

test("Review and create: a refused or server-rejected field is not ready", () => {
  const form = read("components/applications/create-application-form.jsx");
  assert.match(form, /breaksDockerLimit\(config\.name, value\) \|\|\s*Boolean\(form\.formState\.errors\[config\.name\]\);/);
  // The reason shows while typing, from the validation namespace.
  assert.equal(form.match(/patternRule=\{FIELD_PATTERNS\[selected\?\.name\]\?\.\[config\.name\] \?\? dockerLimitRule\(config\.name\)\}/g)?.length, 2);
  assert.match(form, /patternRule\.scope === "validation" \? tv\(patternRule\.message\) : t\(patternRule\.message\)/);
});

test("Disk Cleaner confirm: the keep line and the log note only for what they apply to", () => {
  const docker = { key: "docker_images", keeps_days: false, method: "command" };
  const journal = { key: "journal", keeps_days: true, method: "command" };
  const serviceLogs = { key: "service_logs", keeps_days: true, method: "truncate" };
  assert.deepEqual(confirmNotes([docker]), { keepApplies: false, truncates: false });
  assert.deepEqual(confirmNotes([journal]), { keepApplies: true, truncates: false });
  assert.deepEqual(confirmNotes([serviceLogs]), { keepApplies: true, truncates: true });
  assert.deepEqual(confirmNotes([docker, journal]), { keepApplies: true, truncates: false });
  assert.deepEqual(confirmNotes([]), { keepApplies: false, truncates: false });
  assert.deepEqual(keepApplicable([docker, journal]), [journal]);
  // An older API sends no keeps_days: the choice applies, as before.
  assert.deepEqual(confirmNotes([{ key: "old" }]), { keepApplies: true, truncates: false });

  const panel = strip(read("components/disk-cleaner/cleanup-panel.jsx"));
  assert.match(panel, /\{notes\.truncates \? <p className="text-sm">\{t\("confirm\.truncateNote"\)\}<\/p> : null\}/);
  assert.match(panel, /\{keep != null && notes\.keepApplies \? \(/);
  assert.match(panel, /\{keep === 0 && notes\.keepApplies \? \(/);
  for (const locale of LOCALES) {
    const confirm = messages[locale].diskCleaner.confirm;
    assert.ok(confirm.truncateNote, locale);
    // The log sentence moved out of the description, which now says only that it cannot be undone.
    assert.ok(!confirm.description.includes(confirm.truncateNote), locale);
  }
});

test("SSH card names container login and says when host SSH still works, in every locale", () => {
  for (const locale of LOCALES) {
    const access = messages[locale].applications.containerAccess;
    assert.notEqual(access.stateOn, access.stateOff, locale);
    assert.match(access.hostSshOn, /\{username\}/, locale);
    assert.match(access.hostSshOn, /<link>[^<]+<\/link>/, locale);
    assert.match(access.hostSshOn, /SSH/, locale);
    assert.match(access.commands.turnedOffHostSsh, /\{username\}/, locale);
  }
  assert.equal(messages.en.applications.containerAccess.stateOff, "Container login off");
});
