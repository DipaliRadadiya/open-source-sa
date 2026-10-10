import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

// 9 Oct (Krishna): firewall page restructured — Quick add folded into the rules card.
const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const LOCALES = ["en", "es", "hi", "de", "fr", "pt", "ja", "ru"];
const msg = Object.fromEntries(LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]));

test("a single port is not a range from 0: no SMTP/Docker warning on port 3004", async () => {
  const { riskyExposure } = await import("../lib/firewall/exposure.js");
  const risky = [{ port: 25, label: "SMTP" }, { port: 2375, label: "Docker" }, { port: 3306, label: "mariadb" }];
  // parsePorts gives `to: null` for one port.
  assert.equal(riskyExposure({ port: 3004, portTo: null, riskyPorts: risky }), null);
  assert.equal(riskyExposure({ port: 3306, portTo: null, riskyPorts: risky }), "mariadb");
  assert.equal(riskyExposure({ port: 3306, portTo: "", riskyPorts: risky }), "mariadb");
  assert.equal(riskyExposure({ port: 20, portTo: 30, riskyPorts: risky }), "SMTP");
});

test("Quick add is gone: the add form's service list covers it, the blocked note has the actions", () => {
  for (const f of ["quick-add-card.jsx", "suggested-rules.jsx"]) {
    assert.equal(fs.existsSync(new URL(`../components/firewall/${f}`, import.meta.url)), false, f);
  }
  assert.doesNotMatch(read("app/(app)/firewall/page.jsx"), /QuickAddCard|SuggestedRules/);
  for (const l of LOCALES) assert.equal(msg[l].firewall.quick, undefined, l);
  // The note sits between the status and the rules card, not inside the card.
  const rules = read("components/firewall/rules-card.jsx");
  assert.ok(rules.indexOf("<Note") < rules.indexOf("<Card>"));
});

test("the status card: headline with Turn off beside it, then three facts (Krishna, 9 Oct)", () => {
  const src = read("components/firewall/firewall-status-card.jsx");
  assert.match(src, /<Fact icon=\{ListChecks\} label=\{t\("rules\.title"\)\} value=\{ruleCount\}/);
  assert.match(src, /grid divide-y border-t sm:grid-cols-3 sm:divide-x sm:divide-y-0/);
  assert.doesNotMatch(src, /CardFooter/);
  assert.match(src, /sm:grid-cols-\[auto_minmax\(0,1fr\)_auto\][\s\S]*?col-start-2[\s\S]*?status\.turnOff/);
});

test("each private port is named and opens the add form filled in, without a process name", () => {
  const rules = read("components/firewall/rules-card.jsx");
  // A calm note, not a warning: a private database is the right state (Krishna, 9 Oct: "confusing").
  assert.match(rules, /<Note title=\{t\("rules\.privateTitle", \{ count: blocked\.length \}\)\}>/);
  assert.match(rules, /aria-label=\{t\("rules\.allowPort", \{ port: entry\.port \}\)\}/);
  assert.match(rules, /!\/thread\/i\.test\(entry\.program\)/);
  const block = rules.slice(rules.indexOf("setAdding({"), rules.indexOf("setAdding({") + 400);
  assert.match(block, /ports: String\(entry\.port\)/);
  assert.doesNotMatch(block, /program/);
  const dialog = read("components/firewall/add-rule-dialog.jsx");
  assert.match(dialog, /const controlled = editing \|\| initial !== null;/);
  assert.match(dialog, /defaultValues: valuesFrom\(rule, initial\)/);
});

test("a protected rule that is on shows Protected instead of a refused switch and delete", () => {
  for (const file of ["components/firewall/rules-card.jsx", "components/firewall/rules-cards.jsx"]) {
    const src = read(file);
    assert.match(src, /isLockedOn\(rule, shownEnabled\(rule\)\)/, file);
    assert.match(src, /<ProtectedTag labels=\{labels\} \/>/, file);
  }
  // A Popover, so the reason opens on a phone tap.
  assert.match(read("components/firewall/rule-parts.jsx"), /export function ProtectedTag[\s\S]*?<Popover /);
});

test("table: Allow/Block always shown, state in its own column, port and protocol together, filters past 10", () => {
  const rules = read("components/firewall/rules-card.jsx");
  assert.doesNotMatch(rules, /id: "protocol"/);
  // Krishna, 9 Oct: "why not showing allowed or blocked in table?"
  assert.doesNotMatch(rules, /anyDeny/);
  assert.doesNotMatch(read("components/firewall/rules-cards.jsx"), /anyDeny/);
  const order = ["header: t(\"rules.name\")", "id: \"action\"", "header: t(\"rules.port\")", "id: \"source\"", "id: \"state\"", "id: \"actions\""].map((k) => rules.indexOf(k));
  assert.ok(order.every((i, n) => i > 0 && (n === 0 || i > order[n - 1])), String(order));
  // A protected row keeps the delete button's space, so the pencils line up.
  assert.match(rules, /className=\{locked \? "invisible" : undefined\}/);
  assert.match(rules, /const showFilters = total > 10 \|\| hasFilters;/);
  assert.match(read("app/(app)/firewall/page.jsx"), /total=\{meta\?\.total \?\? rules\.length\}/);
});

test("turning the firewall off is a quiet button; the confirm stays red", () => {
  const src = read("components/firewall/firewall-status-card.jsx");
  assert.match(src, /variant=\{enabled \? "neutral" : "default"\}/);
  assert.match(src, /open=\{confirming === "off"\}[\s\S]*?confirmVariant="destructive"/);
});

test("new firewall strings exist in every locale", () => {
  for (const l of LOCALES) {
    const f = msg[l].firewall;
    assert.ok(f.rules.protectedTag, l);
    assert.ok(f.rules.allowPort.includes("{port}"), l);
    assert.equal(f.add.action, f.add.submit, l);
    assert.doesNotMatch(f.add.description, /tile|ficha|atalho|Schnellfeld|быстр|タイル|टाइल|tuile/i, l);
  }
});
