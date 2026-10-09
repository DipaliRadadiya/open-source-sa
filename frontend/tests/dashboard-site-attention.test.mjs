import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { attentionFindings } from "../lib/dashboard/attention.js";

/*
 * The panel knew one of Krishna's nine sites was serving over plain HTTP with
 * no certificate, and said so nowhere anyone would look. This is the chip that
 * says it — and, more importantly, the rules about when it must stay quiet.
 *
 * `attentionFindings` is a pure function over the applications list, so unlike
 * most of this suite these are real behavioural tests rather than assertions
 * about source text.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const { locales } = await import("../i18n/routing.js");

const site = (over = {}) => ({
  id: 1,
  name: "my-blog",
  status: "active",
  is_disabled: false,
  url: "https://my-blog.example.com",
  ...over,
});

test("a healthy server produces nothing at all", () => {
  assert.deepEqual(attentionFindings([site(), site({ id: 2, name: "shop" })]), []);
});

test("a live site on plain http is found", () => {
  // The real case: github.23-172-120-73.nip.io, active, url still http://.
  const [finding] = attentionFindings([site({ name: "github", url: "http://github.example.com" })]);
  assert.equal(finding.kind, "insecure");
  assert.equal(finding.site, "github");
  // The screen that fixes it, not the site's front page.
  assert.equal(finding.href, "/applications/1/domains");
});

test("a site still provisioning is not accused of having no certificate", () => {
  /*
   * Every site is served over http for the first minutes of its life, before
   * its certificate is issued. Flagging that would put a warning on the
   * dashboard for a few minutes after every single create — which is how a
   * warning chip becomes something people learn to ignore.
   */
  const found = attentionFindings([
    site({ status: "provisioning", url: "http://new.example.com" }),
  ]);
  assert.deepEqual(found.filter((f) => f.kind === "insecure"), []);
});

test("a paused site is left alone", () => {
  // Paused is a thing somebody chose. It is not a fault and must not nag.
  const found = attentionFindings([
    site({ is_disabled: true, url: "http://paused.example.com" }),
  ]);
  assert.deepEqual(found, []);
});

test("a failed site carries the API's own reason, not ours", () => {
  const [finding] = attentionFindings([
    site({ status: "failed", failed_reason_title: "Composer install failed" }),
  ]);
  assert.equal(finding.kind, "failed");
  assert.equal(finding.detail, "Composer install failed");
});

test("a failure with no reason from the API says nothing extra", () => {
  // Rather than inventing a sentence to fill the gap.
  const [finding] = attentionFindings([site({ status: "failed" })]);
  assert.equal(finding.detail, null);
});

test("a lost git account points at the deployment screen", () => {
  const [finding] = attentionFindings([site({ git_account_missing: true })]);
  assert.equal(finding.kind, "git");
  assert.equal(finding.href, "/applications/1/deployment");
});

test("the worst thing is listed first", () => {
  // A site that never came up outranks one that is merely on http.
  const found = attentionFindings([
    site({ id: 1, name: "insecure-one", url: "http://a.example.com" }),
    site({ id: 2, name: "broken-one", status: "failed" }),
  ]);
  assert.deepEqual(found.map((f) => f.kind), ["failed", "insecure"]);
});

test("one site with two problems is reported twice, with distinct ids", () => {
  // Two different fixes on two different screens — collapsing them would hide
  // one of them behind the other.
  const found = attentionFindings([
    site({ url: "http://a.example.com", git_account_missing: true }),
  ]);
  assert.equal(found.length, 2);
  assert.equal(new Set(found.map((f) => f.id)).size, 2);
});

/*
 * The chip became a panel beside Server information (redesign, 6 Oct 2026).
 * It summarises by KIND of problem: a dashboard says what is wrong and where
 * to go, the applications page lists every site.
 */
const panel = read("components/dashboard/attention-panel.jsx");

test("the panel has one row per kind of problem, not per application", () => {
  assert.match(panel, /let kind = kinds\.find\(\(k\) => k\.kind === finding\.kind\)/);
  // One site: its own sentence and its own page. Several: a count and the list.
  assert.match(panel, /one \? t\(`attention\.\$\{kind\}\.chip`, \{ site: items\[0\]\.site \}\) : t\(`attention\.\$\{kind\}\.many`/);
  assert.match(panel, /href=\{one \? items\[0\]\.href : "\/applications"\}/);
});

test("services that stopped come first", () => {
  assert.ok(panel.indexOf("down.length ? (") < panel.indexOf("kinds.map("), "services are no longer first");
});

test("an unanswered applications read is never 'nothing needs attention'", () => {
  // The panel only steps aside when the answer is known; a failed read says so.
  assert.match(panel, /if \(!count && verdictKnown\) return null;/);
  assert.match(panel, /t\("loadFailed"\)/);
});

test("it sits first after the banner, rows side by side", () => {
  const page = read("app/(app)/dashboard/page.jsx");
  assert.ok(page.indexOf("<AttentionPanel") < page.indexOf("<LiveMetricsSection"), "Needs attention is below the live readings again");
  // One line per problem, side by side (Krishna, 6 Oct: the cards pushed Server information down).
  assert.match(panel, /<ul className="grid gap-2 lg:grid-cols-\[repeat\(auto-fit,minmax\(26rem,1fr\)\)\]">/);
});

test("every string the chip can render exists in every locale", () => {
  const get = (o, p) => p.split(".").reduce((a, k) => a?.[k], o);
  const keys = ["title", "viewApplications", "servicesAction"];
  for (const kind of ["insecure", "failed", "deployFailed", "processDown", "git"]) {
    for (const part of ["chip", "detail", "action", "many"]) keys.push(`${kind}.${part}`);
  }

  for (const locale of locales) {
    const attention = JSON.parse(read(`messages/${locale}.json`)).serverDashboard?.attention;
    assert.ok(attention, `${locale} has no serverDashboard.attention`);
    for (const key of keys) {
      assert.equal(typeof get(attention, key), "string", `${locale} is missing ${key}`);
    }
    // The site's name is interpolated, so losing the placeholder would render
    // a sentence about no site in particular.
    for (const kind of ["insecure", "failed", "git"]) {
      assert.match(get(attention, `${kind}.chip`), /\{site\}/, `${locale} ${kind}.chip lost {site}`);
    }
    }
});

test("'Needs attention' keeps the wording the panel already used", () => {
  /*
   * It exists as admin.attention.title. I wrote a second translation of it in
   * seven locales and the one-voice guard caught all seven — "Handlungsbedarf"
   * vs "Erfordert Aufmerksamkeit", "À vérifier" vs "Nécessite votre
   * attention", and so on. Same English, same words, every locale.
   */
  for (const locale of locales) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    const admin = m.admin?.attention?.title;
    if (!admin) continue;
    assert.equal(m.serverDashboard.attention.title, admin, `${locale} says it two ways`);
  }
});
