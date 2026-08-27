import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";
import {
  engineInstallCanRetry,
  engineIsPresent,
  findInstallCandidate,
  findPresentSqlEngine,
  installingEngineName,
  isSqlEngine,
  markEngineInstalling,
} from "../lib/databases/install-lifecycle.js";
import { enginesResponseSchema } from "../lib/schemas/database.js";
import { setupResponseSchema } from "../lib/schemas/setup.js";

const root = path.join(import.meta.dirname, "..");

const engine = (name, values = {}) => ({
  engine: name,
  installable: true,
  installed: false,
  running: false,
  install_status: null,
  ...values,
});

const liveProgress = {
  status: "installing",
  started_at: "27-08-2026 04:00:00",
  started_at_human: "a few seconds ago",
  reason: null,
  message: null,
  reference: null,
  current_step: "starting_service",
  current_step_title: "Starting the database service",
  output: "Setting up mariadb-server ...",
  retryable: false,
};

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

test("the progress contract is authoritative about retryability", () => {
  assert.equal(
    engineInstallCanRetry(
      engine("mongodb", {
        install_reason: "worker",
        install_progress: { ...liveProgress, status: "failed", retryable: false },
      }),
    ),
    false,
  );
  assert.equal(
    engineInstallCanRetry(
      engine("mysql", {
        install_reason: "root_unreachable",
        install_progress: { ...liveProgress, status: "failed", retryable: true },
      }),
    ),
    true,
  );
  assert.equal(
    engineInstallCanRetry(engine("mysql", { install_reason: "root_unreachable" })),
    false,
  );
});

test("both API schemas preserve database installation progress", () => {
  const engines = enginesResponseSchema.parse({
    engines: [
      engine("mariadb", {
        install_status: "installing",
        install_progress: liveProgress,
      }),
    ],
  });
  const setup = setupResponseSchema.parse({
    setup: {
      components: [
        {
          key: "database",
          title: "Database",
          state: "installing",
          progress: liveProgress,
        },
      ],
    },
  });

  assert.deepEqual(engines.engines[0].install_progress, liveProgress);
  assert.deepEqual(setup.setup.components[0].progress, liveProgress);
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
  assert.equal(next[1].install_progress.current_step, "queued");
  assert.equal(next[1].install_progress.output, null);
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
  const setupComponent = fs.readFileSync(
    path.join(root, "components/setup/setup-component.jsx"),
    "utf8",
  );
  const progress = fs.readFileSync(
    path.join(root, "components/databases/database-install-progress.jsx"),
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
  assert.match(bar, /<DatabaseInstallProgress/);
  assert.match(state, /<DatabaseInstallProgress/);
  assert.match(setupComponent, /QUEUED_DATABASE_PROGRESS/);
  assert.match(setupComponent, /<DatabaseInstallProgress/);
  assert.match(progress, /progress\.current_step_title/);
  assert.match(progress, /defaultOpen=\{failed\}/);
  assert.match(progress, /max-h-80 overflow-auto/);
  assert.match(progress, /aria-live="polite"/);
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
