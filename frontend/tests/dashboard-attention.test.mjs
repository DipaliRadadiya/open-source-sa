import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { attentionFindings } from "../lib/dashboard/attention.js";

/*
 * The strip that tells a server owner which application wants them. It watched
 * for three things and got one of them wrong.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",").map((c) => c.trim().replace(/['"]/g, "")).filter(Boolean);

const app = (over) => ({
  id: 1, name: "site", status: "active", url: "https://site.test",
  is_disabled: false, deployed: true, ...over,
});

test("a running application with a failed deploy is not called a failed install", () => {
  /*
   * `failed_step` is set BOTH when provisioning dies and when a deploy dies on
   * a healthy application. One rule matched both, so an application serving
   * traffic was announced as "Setting this application up stopped partway, so
   * it is not serving yet" — both halves false, and the reader sent hunting
   * for a provisioning failure that never happened.
   *
   * The applications list always drew the distinction: red "Failed" against
   * amber "Last deploy failed".
   */
  const deploy = attentionFindings([
    app({ failed_step: "composer_install", repository: "git@github.com:x/y.git" }),
  ]);
  assert.equal(deploy.length, 1);
  assert.equal(deploy[0].kind, "deployFailed");
  assert.match(deploy[0].href, /\/deployment$/, "it links to the deploy log, not the overview");

  const provisioning = attentionFindings([app({ status: "failed", url: null })]);
  assert.equal(provisioning[0].kind, "failed");

  // A failed_step on a NON-git application is not a deploy — nothing to pull.
  assert.deepEqual(attentionFindings([app({ failed_step: "composer_install" })]), []);
});

test("a stopped process is reported, because that one is an outage", () => {
  /*
   * The row badge already computed this and the dashboard never mentioned it,
   * so a site that was down looked healthy on the first screen anyone opens.
   */
  const down = attentionFindings([
    app({ has_process: true, process: { state: "failed" } }),
  ]);
  assert.equal(down[0].kind, "processDown");
  assert.match(down[0].href, /\/workers$/);

  // Running, starting, never deployed, or paused: all silent.
  for (const over of [
    { has_process: true, process: { state: "active" } },
    { has_process: true, process: { state: "activating" } },
    { has_process: true, process: { state: "failed" }, deployed: false },
    { has_process: true, process: { state: "failed" }, is_disabled: true },
    { has_process: false, process: { state: "failed" } },
  ]) {
    assert.deepEqual(attentionFindings([app(over)]), [], JSON.stringify(over));
  }
});

test("a deliberate choice is never reported as a problem", () => {
  /*
   * The file's own rule: alert on something somebody chose and they learn to
   * ignore the chip, which costs you the one time it matters. Paused is a
   * choice; so are no password, no WAF and no fail2ban on a public site.
   */
  assert.deepEqual(attentionFindings([app({ is_disabled: true, disabled_at: "01-01-2026" })]), []);
  assert.deepEqual(
    attentionFindings([app({ basic_auth_enabled: false, waf_enabled: false, fail2ban_enabled: false })]),
    [],
  );
  assert.deepEqual(attentionFindings([app({ status: "provisioning", url: null })]), []);
});

test("every kind it can emit has copy in every locale", () => {
  const kinds = [...read("lib/dashboard/attention.js").matchAll(/key: "(\w+)"/g)].map((m) => m[1]);
  assert.ok(kinds.length >= 5, `expected every kind, found ${kinds.join()}`);
  for (const locale of LOCALES) {
    const ns = JSON.parse(read(`messages/${locale}.json`)).serverDashboard.attention;
    for (const kind of kinds) {
      assert.ok(ns[kind], `${locale} attention.${kind}`);
      for (const part of ["chip", "detail", "action"]) {
        assert.ok(ns[kind][part]?.trim(), `${locale} attention.${kind}.${part}`);
      }
      assert.match(ns[kind].chip, /\{site\}/, `${locale} attention.${kind}.chip must name the application`);
    }
  }
});
