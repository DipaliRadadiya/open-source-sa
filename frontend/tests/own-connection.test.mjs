import test from "node:test";
import assert from "node:assert/strict";
import { isPanelProcess, panelUsernames } from "../lib/databases/own-connection.js";

const CONNECTIONS = [
  { engine: "mariadb", username: "panel_kyvnfflie2" },
  { engine: "mongodb", username: "panel_mongo" },
];

test("the panel's own connection is recognised", () => {
  /*
   * The reported bug. On a quiet server the ONLY row in Running queries is the
   * panel's monitoring connection — the one that produced the list you are
   * looking at — so the single Stop button on screen kills the page's own
   * lifeline.
   */
  const users = panelUsernames(CONNECTIONS, "mariadb");
  assert.equal(isPanelProcess({ user: "panel_kyvnfflie2" }, users), true);
  // Some drivers report user@host.
  assert.equal(isPanelProcess({ user: "panel_kyvnfflie2@localhost" }, users), true);
});

test("somebody else's query stays stoppable", () => {
  // The card's whole purpose. Over-blocking would be a worse bug than the one
  // being fixed.
  const users = panelUsernames(CONNECTIONS, "mariadb");
  assert.equal(isPanelProcess({ user: "wordpress" }, users), false);
  assert.equal(isPanelProcess({ user: "root" }, users), false);
  assert.equal(isPanelProcess({ user: "panel_mongo" }, users), false, "other engine");
});

test("the engine filter is what keeps the two apart", () => {
  assert.deepEqual([...panelUsernames(CONNECTIONS, "mariadb")], ["panel_kyvnfflie2"]);
  assert.deepEqual([...panelUsernames(CONNECTIONS, "mongodb")], ["panel_mongo"]);
  // No engine given: every panel account, which is the safe direction.
  assert.equal(panelUsernames(CONNECTIONS).size, 2);
});

test("not knowing blocks nothing", () => {
  /*
   * If `/databases/connections` fails we cannot tell whose connection this is.
   * Blocking every row on a guess would take away the feature; the guard stays
   * out of the way and the API still refuses what it must.
   */
  assert.equal(isPanelProcess({ user: "anyone" }, panelUsernames([], "mariadb")), false);
  assert.equal(isPanelProcess({ user: "anyone" }, new Set()), false);
  assert.equal(isPanelProcess({ user: "anyone" }, undefined), false);
  assert.equal(isPanelProcess({}, panelUsernames(CONNECTIONS, "mariadb")), false);
  assert.equal(isPanelProcess(undefined, panelUsernames(CONNECTIONS, "mariadb")), false);
});

test("a connection row with no username is ignored, not matched as blank", () => {
  // Otherwise a null username would build a set containing "" and match every
  // process whose user is also missing.
  const users = panelUsernames([{ engine: "mariadb", username: null }], "mariadb");
  assert.equal(users.size, 0);
  assert.equal(isPanelProcess({ user: "" }, users), false);
});
