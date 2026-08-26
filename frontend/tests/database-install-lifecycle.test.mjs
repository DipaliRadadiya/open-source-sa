import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import {
  engineIsPresent,
  findInstallCandidate,
  findPresentSqlEngine,
  installingEngineName,
  isSqlEngine,
  markEngineInstalling,
} from "../lib/databases/install-lifecycle.js";

const root = path.join(import.meta.dirname, "..");

const engine = (name, values = {}) => ({
  engine: name,
  installable: true,
  installed: false,
  running: false,
  install_status: null,
  ...values,
});

test("SQL identity does not depend on a driver field", () => {
  assert.equal(isSqlEngine(engine("mysql", { driver: null })), true);
  assert.equal(isSqlEngine(engine("mariadb", { driver: "anything" })), true);
  assert.equal(isSqlEngine(engine("mongodb", { driver: "sql" })), false);
});

test("an installed but unreachable SQL engine still owns the SQL slot", () => {
  const mysql = engine("mysql", { installed: true, running: false });
  const found = findPresentSqlEngine([mysql, engine("mariadb")]);

  assert.equal(engineIsPresent(mysql), true);
  assert.equal(found, mysql);
});

test("an active install blocks every second install candidate", () => {
  const rows = [
    engine("mysql", { install_status: "installing" }),
    engine("mongodb"),
  ];

  assert.equal(installingEngineName(rows), "mysql");
  assert.equal(findInstallCandidate(rows), null);
});

test("a running SQL engine leaves MongoDB as the addable engine", () => {
  const rows = [
    engine("mysql", { installed: true, running: true }),
    engine("mariadb"),
    engine("mongodb"),
  ];

  assert.equal(findInstallCandidate(rows)?.engine, "mongodb");
});

test("an installed but unreachable SQL engine blocks every other SQL install", () => {
  const rows = [
    engine("mongodb", { installed: true, running: true }),
    engine("mysql", { installed: true, running: false }),
    engine("mariadb"),
  ];

  assert.equal(findInstallCandidate(rows), null);
});

test("a failed secondary install is preferred so Retry cannot disappear", () => {
  const rows = [
    engine("mysql", { installed: true, running: true }),
    engine("mariadb"),
    engine("mongodb", {
      install_status: "failed",
      install_reason: "worker",
      install_message: "The worker stopped.",
    }),
  ];

  assert.equal(findInstallCandidate(rows)?.engine, "mongodb");
});

test("marking a queued engine is immediate and clears the previous failure", () => {
  const rows = [
    engine("mysql"),
    engine("mongodb", {
      install_status: "failed",
      install_reason: "worker",
      install_message: "The worker stopped.",
    }),
  ];
  const next = markEngineInstalling(rows, "mongodb");

  assert.equal(next[0], rows[0]);
  assert.equal(next[1].install_status, "installing");
  assert.equal(next[1].install_reason, null);
  assert.equal(next[1].install_message, null);
  assert.equal(rows[1].install_status, "failed");
});

test("all three install entry points hand queued work to persistent polling", () => {
  const confirm = fs.readFileSync(
    path.join(root, "components/databases/install-confirm.jsx"),
    "utf8",
  );
  const bar = fs.readFileSync(
    path.join(root, "components/databases/engine-bar.jsx"),
    "utf8",
  );
  const state = fs.readFileSync(
    path.join(root, "components/databases/engine-state.jsx"),
    "utf8",
  );
  const setup = fs.readFileSync(
    path.join(root, "components/setup/setup-checklist.jsx"),
    "utf8",
  );
  const polling = fs.readFileSync(
    path.join(root, "components/databases/use-engine-install-polling.js"),
    "utf8",
  );

  assert.match(confirm, /const effectiveEngine = engine \?\? picked/);
  assert.match(confirm, /setPicked\(\{ engine: sql, driver: "sql" \}\)/);
  assert.match(confirm, /if \(pending\) return/);
  assert.match(confirm, /installEngine\(engineName\)/);
  assert.match(confirm, /catch \(error\)[\s\S]*toast\.error/);
  assert.match(confirm, /onSuccess\?\.\(\{[\s\S]*engine: engineName,[\s\S]*queued:/);
  assert.doesNotMatch(confirm, /useEffect/);
  assert.match(bar, /useEngineInstallPolling\(engines\)/);
  assert.match(bar, /if \(queued\) markStarted\(engine\)/);
  assert.match(state, /useEngineInstallPolling\(engines\)/);
  assert.match(state, /if \(queued\) markStarted\(engine\)/);
  assert.match(setup, /setStarted\(\(s\) =>/);
  assert.match(polling, /enginesResponseSchema\.safeParse\(data\)/);
  assert.match(polling, /setInterval\(poll, POLL_MS\)/);
  assert.match(polling, /markEngineInstalling\(rows, engineName\)/);
  assert.match(polling, /router\.refresh\(\)/);
});

test("form validation derives labels without reading refs during render", () => {
  const form = fs.readFileSync(
    path.join(root, "components/ui/form.jsx"),
    "utf8",
  );

  assert.match(form, /function formLabelText\(children\)/);
  assert.match(form, /field \?\? detectedLabel \?\? null/);
  assert.doesNotMatch(form, /labelRef\.current/);
});
