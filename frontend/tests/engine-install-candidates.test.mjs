import test from "node:test";
import assert from "node:assert/strict";
import {
  findInstallCandidate,
  findInstallCandidates,
} from "../lib/databases/install-lifecycle.js";

const engine = (name, extra = {}) => ({
  engine: name,
  driver: name === "mongodb" ? "mongo" : "sql",
  installable: true,
  installed: false,
  running: false,
  install_status: null,
  ...extra,
});

const FRESH = [engine("mysql"), engine("mariadb"), engine("mongodb")];
const WITH_MARIADB = [
  engine("mysql"),
  engine("mariadb", { installed: true, running: true }),
  engine("mongodb"),
];

test("a fresh server can add any of the three", () => {
  assert.deepEqual(
    findInstallCandidates(FRESH).map((e) => e.engine),
    ["mysql", "mariadb", "mongodb"],
  );
});

test("once a SQL engine is present the other SQL engine is not offered", () => {
  assert.deepEqual(
    findInstallCandidates(WITH_MARIADB).map((e) => e.engine),
    ["mongodb"],
  );
});

test("a retryable failure is offered first", () => {
  const list = [
    engine("mongodb"),
    engine("mysql", { install_status: "failed", install_reason: "network" }),
    engine("mariadb"),
  ];
  assert.equal(findInstallCandidates(list)[0].engine, "mysql");
});

test("nothing is offered while an install is running", () => {
  const busy = [engine("mysql", { install_status: "installing" }), engine("mongodb")];
  assert.deepEqual(findInstallCandidates(busy), []);
});

test("the singular helper is the first of the plural", () => {
  assert.equal(findInstallCandidate(FRESH).engine, "mysql");
  assert.equal(findInstallCandidate(WITH_MARIADB).engine, "mongodb");
  assert.equal(findInstallCandidate([]), null);
});
