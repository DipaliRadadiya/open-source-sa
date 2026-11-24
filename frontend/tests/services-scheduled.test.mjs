import { before, beforeEach, test } from "node:test";
import assert from "node:assert/strict";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
let services;
before(async () => {
  services = await loadComponent("lib/api/services.js", { "@/lib/api/client": "tests/support/services-client-stub.js" });
  Object.assign(services.SCHEDULED_TIMING, { firstCheckMs: 1, everyMs: 1, giveUpMs: 200 });
});
let lists;
beforeEach(() => {
  lists = 0;
});

function server({ put, statuses }) {
  globalThis.__servicesApi = {
    put: async () => put,
    get: async () => {
      const next = statuses[Math.min(lists++, statuses.length - 1)];
      if (next instanceof Error) throw next;
      return { status: 200, data: { services: [{ key: "nginx", status: next }] } };
    },
  };
}

// 24 Nov: the panel's own web server now restarts after answering (202, `scheduled`).
test("a scheduled restart resolves only once the unit is running again", async () => {
  server({ put: { status: 202, data: { service: { key: "nginx", scheduled: "restart" } } }, statuses: [new Error("down"), "activating", "active"] });
  await services.runServiceAction("nginx", "restart");
  assert.equal(lists, 3);
});

test("a scheduled restart that leaves the unit failed is not reported as done", async () => {
  server({ put: { status: 202, data: { service: { key: "nginx", scheduled: "restart" } } }, statuses: ["failed"] });
  await assert.rejects(services.runServiceAction("nginx", "restart"), (error) => !error.response);
});

test("an action run in place answers at once, with no waiting", async () => {
  server({ put: { status: 200, data: { service: { key: "redis", status: "active" } } }, statuses: ["active"] });
  await services.runServiceAction("redis", "restart");
  assert.equal(lists, 0);
});
