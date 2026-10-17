import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import vm from "node:vm";
import { createRequire } from "node:module";
import React from "react";
import { renderToStaticMarkup } from "react-dom/server";

const require = createRequire(import.meta.url);
import { isIpOrCidr } from "../lib/validation/ip.js";

const read = (p) => fs.readFileSync(new URL(`../${p}`, import.meta.url), "utf8");
const card = (name) => read(`components/fail2ban/${name}.jsx`);
const locales = ["en", "es", "de", "fr", "pt", "ja", "ru", "hi"];

function section(source, start, end) {
  const from = source.indexOf(start);
  const to = source.indexOf(end, from + start.length);
  assert.ok(from >= 0 && to > from, `Missing section ${start} → ${end}`);
  return source.slice(from, to);
}

test("Fail2ban editors split 60/40 only in usable desktop containers, left stays natural and tabs retain drafts", () => {
  const page = read("app/(app)/fail2ban/page.jsx");
  const settings = section(page, "settings={", "          />\n          </BrowserIpProvider>");
  assert.match(settings, /@container\/fail2ban-rules/);
  assert.match(settings, /grid items-start gap-4 xl:@4xl\/fail2ban-rules:grid-cols-\[minmax\(0,3fr\)_minmax\(0,2fr\)\]/);
  assert.doesNotMatch(settings, /items-stretch|h-full/);
  assert.match(settings, /<BanRulesCard[\s\S]*<IgnoreListCard/);
  assert.equal((card("fail2ban-tabs").match(/<TabsContent[^>]*forceMount/g) ?? []).length, 2);
});

test("jail status badge groups with the name above ID and preserves comfortable switch layout", () => {
  const source = card("jails-card");
  assert.match(source, /cn\("grid gap-3", jails\.length > 1 && "lg:grid-cols-2"\)/);
  const twoUp = vm.runInNewContext(`(jails) => ${source.match(/jails\.length > 1/)[0]}`);
  for (const count of [0, 1, 2, 6]) assert.equal(twoUp(Array(count)), count >= 2);
  assert.match(source, /grid-cols-\[minmax\(0,1fr\)_auto\] items-center gap-3 rounded-lg border p-4/);
  assert.match(source, /flex min-w-0 items-center gap-2/);
  assert.match(source, /shrink-0 max-w-full rounded/);
  assert.match(source, /overflow-wrap:anywhere/);
  assert.ok(source.indexOf('<JailState jail={jail} t={t} />') < source.indexOf('{jail.name}</span>'));
  assert.doesNotMatch(source, /@container\/jail|@md\/jail|row-start-2 min-w-0/);
  assert.match(source, /col-start-2 row-start-1 flex justify-end/);
  assert.match(source, /checked=\{shown\(jail\)\}/);
  assert.match(source, /pending=\{pending === jail.name\}/);
  assert.match(source, /disabled=\{!canManage\}/);
  assert.match(source, /jail\.stats\?\.currently_failed > 0 && "border-warning\/40 bg-warning\/5"/);
  assert.match(source, /<Badge variant=\{variant\} className="font-normal" aria-label=\{text\}>/);
  assert.match(source, /aria-label=\{text\}/);
  assert.match(source, /<span tabIndex=\{0\}/);
});

test("jail explainer lives in the header with a stable accessible relationship", () => {
  const source = card("jails-card");
  const header = section(source, "        <CardHeader", "        <CardContent");
  assert.match(header, /t\("jails.whatIs"\)/);
  assert.match(header, /aria-expanded=\{explaining\}/);
  assert.match(header, /aria-controls=\{explainerId\}/);
  assert.match(header, /CardDescription className="col-span-2 row-start-2"/);
  assert.match(source, /const explainerId = useId\(\)/);
  assert.match(source, /id=\{explainerId\}\s+hidden=\{!explaining\}/);
});

test("jail state distinguishes off, unreported, quiet, failing and banned activity", () => {
  const source = section(card("jails-card"), "  const failing =", "  const line =");
  const state = vm.runInNewContext(`(jail, t) => { ${source}; return { text, tone, label, variant }; }`);
  const t = (key, values) => `${key}:${values?.count ?? ""}`;
  for (const [jail, expected] of [
    [{ enabled: false, stats: { currently_failed: 9 } }, "jails.stateOff:"],
    [{ enabled: true, stats: null }, "jails.stateUnknown:"],
    [{ enabled: true, stats: {} }, "jails.stateQuiet:"],
    [{ enabled: true, stats: { currently_failed: 2, currently_banned: 8 } }, "jails.stateFailing:2"],
    [{ enabled: true, stats: { currently_failed: 0, currently_banned: 1 } }, "jails.stateBanned:1"],
  ]) assert.equal(state(jail, t).text, expected);
  assert.match(state({ enabled: true, stats: { currently_failed: 1 } }, t).tone, /text-warning/);
});

test("risky jail enable still asks first, while off and non-risky changes apply", () => {
  const source = section(card("jails-card"), "  function toggle(", "\n  return (");
  const calls = [];
  const toggle = vm.runInNewContext(`${source}; toggle`, {
    setGuarding: (guard) => calls.push(["guard", guard.jail.name, guard.enabled]),
    apply: (jail, enabled) => calls.push(["apply", jail.name, enabled]),
  });
  toggle({ name: "sshd", lockout_risk: true }, true);
  toggle({ name: "sshd", lockout_risk: true }, false);
  toggle({ name: "web", lockout_risk: false }, true);
  assert.deepEqual(calls, [["guard", "sshd", true], ["apply", "sshd", false], ["apply", "web", true]]);
  // The ban rules only: the ignore list is never resent from a page's copy.
  assert.match(card("jails-card"), /\.\.\.settingsPayload\(settings\)/);
  // Your address goes on the list before the jail starts, one entry, not the whole list.
  assert.match(card("jails-card"), /await ensureIgnored\(addIp\);\s*await updateFail2ban\(/);
  assert.match(card("recommended-setup"), /await ensureIgnored\(yourIp\);\s*await updateFail2ban\(/);
  for (const name of ["jails-card", "recommended-setup", "ban-rules-card"]) assert.doesNotMatch(card(name), /ignore_ips:/, name);
  assert.match(card("jails-card"), /acknowledged: true/);
});

test("banned table binds deliberate shares and exposes full IP and jail values", () => {
  const source = card("banned-card");
  const columns = section(source, "  const columns =", "\n  return (");
  const shares = [...columns.matchAll(/w-\[(\d+)%\]/g)].map((match) => Number(match[1]));
  assert.deepEqual(shares, [28, 16, 24, 20, 12]);
  assert.equal(shares.reduce((sum, share) => sum + share, 0), 100);
  assert.match(source, /<DataTable\s+bare\s+fixedLayout/);
  assert.match(source, /tabIndex=\{0\} className="block truncate rounded font-mono text-sm"/);
  assert.match(source, /<TooltipContent[^>]*>\{row\.original\.ip\}<\/TooltipContent>/);
  assert.match(source, /<TooltipContent[^>]*>\{row\.original\.jail\}<\/TooltipContent>/);
  assert.match(source, /const \{ seconds_left: left, expires_at: expires, banned_at: since \} = ban/);
  assert.match(source, /if \(!since\)[^\n]+t\("banned.unknownLeft"\)/);
});

test("Ban stays primary, utility actions neutral and unban confirmations destructive", () => {
  const source = card("banned-card");
  const header = section(source, "      <CardHeader", "      <CardContent");
  assert.ok(header.indexOf("<RefreshButton") < header.indexOf("{logHref ?"));
  assert.ok(header.indexOf("{logHref ?") < header.indexOf("{banned.length > 0 ?"));
  assert.ok(header.indexOf("{banned.length > 0 ?") < header.indexOf("<BanIpDialog"));
  assert.doesNotMatch(header, /order-|flex-row-reverse/);
  assert.match(header, /variant="neutral"[\s\S]*onClick=\{\(\) => setConfirmAll\(true\)\}/);
  assert.match(header, /<Button variant="neutral" asChild>/);
  assert.equal((source.match(/confirmVariant="destructive"/g) ?? []).length, 2);
  const actions = section(source, "function ActionsCell", "export function BannedCard");
  assert.match(actions, /t\("banned.unban"\)/);
  assert.doesNotMatch(actions, /size="icon/);
  assert.match(actions, /disabled=\{!canManage \|\| unbanning === row.original.ip\}/);
});

test("mobile bans show complete IPv6/jail names and keep labelled per-address unban", () => {
  const source = card("banned-cards");
  assert.match(source, /className="break-all font-mono text-sm font-medium">\{ban.ip\}/);
  assert.doesNotMatch(source, /truncate/);
  assert.match(source, /max-w-full break-all whitespace-normal/);
  assert.match(source, /t\("banned.timeLeft"\)/);
  assert.match(source, /onClick=\{\(\) => onRequestUnban\(ban\)\}/);
  assert.match(source, /t\("banned.unban"\)/);
  assert.match(card("banned-card"), /"lg:hidden"/);
});

test("ban fields pair with a draft preview and retain bounds, custom presets and permanent handling", () => {
  const source = card("ban-rules-card");
  assert.match(source, /grid gap-x-6 gap-y-5 @md\/ban-rules:grid-cols-2/);
  assert.match(source, /t\("settings.previewTitle"\)/);
  assert.match(source, /maxretryError \|\| findtimeError \? t\("settings.previewInvalid"\)/);
  assert.match(source, /t\("settings.previewHint"\)/);
  assert.ok(source.indexOf('id="f2b-bantime"') < source.indexOf('t.rich(isPermanent'));
  assert.match(source, /saveLabel=\{t\("settings.saveRules"\)\}/);
  assert.match(source, /Number\(bantime\) === PERMANENT_BANTIME/);
  assert.match(source, /!hasCurrent \? \(/);
  const range = source.match(/const inRange = (.*);/)[1];
  const inRange = vm.runInNewContext(range);
  for (const value of ["2", "5", "100"]) assert.equal(inRange(value, 2, 100), true);
  for (const value of ["", "1", "101", "2.5", "-1", "abc"]) assert.equal(inRange(value, 2, 100), false);
  assert.equal(inRange("30", 30, 86400), true);
  assert.equal(inRange("86400", 30, 86400), true);
  assert.equal(inRange("86401", 30, 86400), false);
});

function ignoreFixture(initial = [], { fail = {} } = {}) {
  const source = card("ignore-list-card");
  const calls = [];
  const context = {
    ips: [...initial], draft: "", isIpOrCidr, MAX_IGNORE_IPS: 100,
    t: (key) => key,
    apiMessage: (error, fallback) => error?.response?.data?.message ?? fallback,
    setDraftError: (error) => { context.error = error; },
    setDraft: (draft) => { context.draft = draft; },
    setAdding: (value) => calls.push(["adding", value]),
    setRemoving: () => {},
    setConfirmRemove: (ip) => { context.confirm = ip; },
    addIgnoreIp: async (ip) => { calls.push(["post", ip]); if (fail.post) throw fail.post; },
    removeIgnoreIp: async (ip, options) => {
      calls.push(["delete", ip, Boolean(options?.acknowledged)]);
      const error = fail.delete?.shift?.();
      if (error) throw error;
    },
    refreshAndWait: async () => calls.push(["refresh"]),
    toast: { success: (m) => calls.push(["success", m]), error: (m) => calls.push(["error", m]) },
    calls,
  };
  const code = section(source, "  async function add(", "  return (\n    <DisabledReasonProvider");
  vm.runInNewContext(`${code}; this.add = add; this.remove = remove;`, context);
  return context;
}

test("Add checks the address here, then sends that one entry and re-reads the list", async () => {
  const fixture = ignoreFixture();
  fixture.draft = " 203.0.113.5 ";
  await fixture.add(" 203.0.113.5 ");
  assert.deepEqual(fixture.calls, [["adding", true], ["post", "203.0.113.5"], ["refresh"], ["success", "settings.ignoreAdded"], ["adding", false]]);
  assert.equal(fixture.draft, "");
  await fixture.add("not an IP");
  assert.equal(fixture.error, "settings.invalidIp");
  assert.equal(fixture.calls.filter(([c]) => c === "post").length, 1, "an invalid address never leaves the page");
  const full = ignoreFixture(Array.from({ length: 100 }, (_, i) => `203.0.113.${i}`));
  await full.add("198.51.100.1");
  assert.equal(full.error, "settings.ignoreLimit");
  await full.add("203.0.113.2");
  assert.equal(full.error, null, "an existing address remains a no-op even at capacity");
  assert.deepEqual(full.calls, []);
});

test("an Add the server refuses shows its reason and re-reads the list that changed elsewhere", async () => {
  const refused = { response: { status: 422, data: { message: "That address is already on the ignore list." } } };
  const fixture = ignoreFixture([], { fail: { post: refused } });
  await fixture.add("203.0.113.5");
  assert.equal(fixture.error, "That address is already on the ignore list.");
  assert.deepEqual(fixture.calls, [["adding", true], ["post", "203.0.113.5"], ["refresh"], ["adding", false]]);
});

test("remove sends one entry; the server's lockout answer asks, and only then is it acknowledged", async () => {
  const lockout = { response: { status: 422, data: { message: "risk" } } };
  const fixture = ignoreFixture(["203.0.113.0/24"], { fail: { delete: [lockout] } });
  await fixture.remove("203.0.113.0/24");
  assert.equal(fixture.confirm, "203.0.113.0/24", "a range covering you asks too: the server decides");
  assert.deepEqual(fixture.calls, [["delete", "203.0.113.0/24", false]]);
  await fixture.remove("203.0.113.0/24", { acknowledged: true });
  assert.equal(fixture.confirm, null);
  assert.deepEqual(fixture.calls.slice(1), [["delete", "203.0.113.0/24", true], ["refresh"], ["success", "settings.ignoreRemoved"]]);

  const gone = ignoreFixture(["198.51.100.1"], { fail: { delete: [{ response: { status: 404, data: { message: "missing" } } }] } });
  await gone.remove("198.51.100.1");
  assert.deepEqual(gone.calls, [["delete", "198.51.100.1", false], ["error", "missing"], ["refresh"]]);
  assert.equal(gone.confirm, undefined, "a 404 is not a lockout");
});

test("the ignore list has no draft and no Save: every change is its own request", () => {
  const source = card("ignore-list-card");
  assert.match(source, /<Label htmlFor="f2b-ignore-address">\{t\("settings.inputLabel"\)\}/);
  assert.match(source, /id="f2b-ignore-address"/);
  assert.match(source, /t\("settings.ignoreDraftHint"\)/);
  assert.doesNotMatch(source, /CardSaveFooter|saveAddresses|updateFail2ban|setEdited/);
  assert.match(source, /data-slot="caution"/);
  assert.match(source, /variant="outline"[\s\S]*onClick=\{\(\) => add\(yourIp\)\}/);
  assert.doesNotMatch(source, /IconTooltip|icon=\{Plus\}|size="icon"/);
});

test("both settings saves send the complete object and wait for re-read before success", async () => {
  for (const [name, end, expected] of [
    // No ignore list: omitted, the server keeps its own (17 Oct).
    ["ban-rules-card", "  function discard(", { bantime: -1, findtime: 600, maxretry: 5 }],
  ]) {
    const calls = [];
    const save = vm.runInNewContext(`${section(card(name), "  async function save(", end)}; save`, {
      settings: { bantime: 3600, findtime: 300, maxretry: 6, ignore_ips: ["127.0.0.1"] },
      bantime: "-1", findtime: "600", maxretry: "5", ips: ["127.0.0.1", "203.0.113.5"],
      setSaving: (value) => calls.push(["pending", value]),
      updateFail2ban: async (payload) => calls.push(["write", JSON.parse(JSON.stringify(payload))]),
      refreshAndWait: async () => calls.push(["refresh"]),
      t: (key) => key, apiMessage: () => "error",
      toast: { success: () => calls.push(["success"]), error: () => calls.push(["error"]) },
    });
    await save();
    assert.deepEqual(calls, [["pending", true], ["write", expected], ["refresh"], ["success"], ["pending", false]], name);
  }
});

test("rejected settings writes report errors without success, refresh or clearing the draft", async () => {
  for (const [name, end] of [["ban-rules-card", "  function discard("]]) {
    for (const status of [403, 500, null]) {
      const calls = [];
      const context = {
        settings: { bantime: 3600, findtime: 300, maxretry: 6, ignore_ips: [] },
        bantime: "-1", findtime: "600", maxretry: "5", ips: ["203.0.113.5"],
        setSaving: (value) => calls.push(["pending", value]),
        updateFail2ban: async () => { throw status ? { response: { status } } : new Error("Network error"); },
        refreshAndWait: async () => calls.push(["refresh"]),
        t: (key) => key, apiMessage: () => "settings.failed",
        toast: { success: () => calls.push(["success"]), error: (message) => calls.push(["error", message]) },
      };
      const save = vm.runInNewContext(`${section(card(name), "  async function save(", end)}; save`, context);
      await save();
      assert.deepEqual(calls, [["pending", true], ["error", "settings.failed"], ["pending", false]], `${name}: ${status}`);
      assert.equal(context.bantime, "-1");
      assert.deepEqual(context.ips, ["203.0.113.5"]);
    }
  }
});

test("loading mirrors status, comfortable two-up jails, desktop shares and mobile ban cards", () => {
  const source = read("app/(app)/fail2ban/loading.jsx");
  assert.match(source, /aria-busy="true"/);
  assert.match(source, /<SkHeader \/>/);
  assert.match(source, /<SkTabs count=\{2\} \/>/);
  assert.match(source, /grid gap-3 lg:grid-cols-2/);
  assert.match(source, /items-center gap-3 rounded-lg border p-4/);
  assert.match(source, /flex flex-wrap items-center gap-2/);
  assert.match(source, /h-6 w-20 max-w-full rounded-full/);
  assert.doesNotMatch(source, /@container\/jail|@md\/jail/);
  assert.match(source, /grid-cols-\[28fr_16fr_24fr_20fr_12fr\]/);
  assert.match(source, /grid grid-cols-1 gap-3 sm:grid-cols-2 lg:hidden/);
  assert.doesNotMatch(source, /SkCentered/);
});

test("all eight locales have specific save labels, list guidance and the own-IP placeholder", () => {
  const dictionaries = locales.map((locale) => [locale, JSON.parse(read(`messages/${locale}.json`)).fail2ban.settings]);
  for (const [locale, settings] of dictionaries) {
    for (const key of ["saveRules", "ignoreHint", "ignoreDraftHint", "inputLabel", "ignoreAdded", "ignoreRemoved"]) assert.ok(settings[key], `${locale}: ${key}`);
    for (const key of ["ignoreAdded", "ignoreRemoved", "removeYouDescription"]) assert.match(settings[key], /\{ip\}/, `${locale}: ${key}`);
    assert.match(settings.yourIpMissing, /\{ip\}/, locale);
    assert.doesNotMatch(settings.ignoreHint, /can never lock you out/i);
    if (locale !== "en") for (const key of ["saveRules", "ignoreDraftHint", "inputLabel", "ignoreAdded", "ignoreRemoved"]) assert.notEqual(settings[key], dictionaries[0][1][key], `${locale}: translated ${key}`);
  }
});


test("compact badge labels retain all state priorities and reported counts", () => {
  const source = section(card("jails-card"), "  const failing =", "  const line =");
  const state = vm.runInNewContext(`(jail, t) => { ${source}; return { label, variant }; }`);
  const t = (key, values) => `${key}:${values?.count ?? ""}`;
  for (const [jail, label, variant] of [
    [{ enabled: false, stats: { currently_failed: 9 } }, "jails.badgeOff:", "muted"],
    [{ enabled: true, stats: null }, "jails.badgeEnabled:", "muted"],
    [{ enabled: true, stats: {} }, "jails.badgeEnabled:", "success"],
    [{ enabled: true, stats: { currently_failed: 2, currently_banned: 8 } }, "jails.badgeFailing:2", "warning"],
    [{ enabled: true, stats: { currently_failed: 0, currently_banned: 1 } }, "jails.badgeBanned:1", "success"],
  ]) {
    const result = state(jail, t);
    assert.equal(result.label, label); assert.equal(result.variant, variant);
  }
});

test("Fail2ban uses shared status pills and label badges without bespoke badge dimensions", () => {
  const source = card("jails-card");
  const badge = source.match(/<Badge variant=\{variant\}[^>]*>/)?.[0];
  assert.ok(badge);
  assert.match(badge, /className="font-normal"/);
  assert.doesNotMatch(badge, /h-auto|h-\d|rounded-|px-|py-|font-medium|whitespace-normal/);
  const addresses = card("ignore-list-card");
  assert.match(addresses, /<Badge variant="outline" className="font-normal">\{t\("settings.entriesCount"/);
  assert.match(addresses, /<Badge variant="success" className="font-normal">\s*\{t\("settings.you"/);
  const shared = read("components/ui/badge.jsx");
  assert.match(shared, /success:\s*"h-6 px-2\.5/);
  assert.match(shared, /muted:\s*"h-6 px-2\.5/);
  assert.match(shared, /warning:\s*"h-6 px-2\.5/);
});

test("address entry precedes list and error, count and named footer remain correctly scoped", () => {
  const source = card("ignore-list-card");
  assert.ok(source.indexOf('id="f2b-ignore-address"') < source.indexOf('          <ul\n'));
  assert.ok(source.indexOf('id="f2b-ignore-error"') < source.indexOf('          <ul\n'));
  assert.match(source, /t\("settings.entriesCount", \{ count: ips\.length \}\)/);
  assert.match(source, /TooltipContent>\{t\("settings.removeIp", \{ ip \}\)\}/);
  assert.match(source, /grid grid-cols-\[minmax\(0,1fr\)_auto\] items-start gap-2/);
  assert.match(source, /className="inline-flex shrink-0"/);
  assert.match(source, /px-3 py-4 text-sm text-muted-foreground/);
});

test("new compact statuses, draft preview and entry count are translated in all eight locales", () => {
  for (const locale of locales) {
    const f = JSON.parse(read(`messages/${locale}.json`)).fail2ban;
    for (const key of ["badgeOff", "badgeEnabled", "badgeFailing", "badgeBanned"]) assert.ok(f.jails[key], `${locale}: ${key}`);
    for (const key of ["badgeFailing", "badgeBanned"]) assert.match(f.jails[key], /\{count\}/, `${locale}: ${key}`);
    for (const key of ["previewTitle", "previewInvalid", "previewHint", "entriesCount"]) assert.ok(f.settings[key], `${locale}: ${key}`);
    assert.match(f.settings.entriesCount, /\{count, plural,/);
    assert.doesNotMatch(f.settings.summary, /Right now:/);
    if (locale === "en") {
      assert.doesNotMatch(f.jails.stateQuiet, /watching/i);
      assert.doesNotMatch(f.jails.stateUnknown, /watching/i);
      assert.match(f.settings.summary, /With these values/);
    }
  }
});


test("desktop list scrolls within the remaining height but never grows to fill it; static controls stay outside native scroll", () => {
  const source = card("ignore-list-card");
  assert.match(source, /xl:@4xl\/fail2ban-rules:h-0 xl:@4xl\/fail2ban-rules:min-h-full/);
  assert.match(source, /CardHeader className="flex-none border-b/);
  assert.match(source, /CardContent className="flex min-h-0 flex-1 flex-col gap-3/);
  assert.match(source, /data-slot="caution" className="flex flex-none/);
  assert.match(source, /<div className="flex-none space-y-2">/);
  assert.doesNotMatch(source, /CardSaveFooter/);
  const list = section(source, "          <ul\n", "          </ul>");
  assert.match(list, /tabIndex=\{0\}/);
  assert.match(list, /aria-label=\{t\("settings.ignore"\)\}/);
  assert.match(list, /min-h-0 max-h-72 overflow-y-auto overscroll-contain/);
  assert.match(list, /focus-visible:outline-2/);
  assert.match(list, /xl:@4xl\/fail2ban-rules:max-h-none"/);
  // Grown to the card's height, its last row looked tall (Krishna, 15 Oct).
  assert.doesNotMatch(list, /flex-1/);
  assert.doesNotMatch(list, /CardSaveFooter|caution|f2b-ignore-(address|hint|examples|error)|slice\(/);
  assert.doesNotMatch(source, /max-h-\[(?:\d+px|\d+rem)\]|ResizeObserver|useEffect/);
  const provider = section(read("components/ui/reason-tooltip.jsx"), "export function DisabledReasonProvider", "// The span");
  assert.doesNotMatch(provider, /<div|<span/);
});

test("inline input uses accessible full examples, errors and short non-parsed placeholder in all locales", () => {
  const source = card("ignore-list-card");
  assert.match(source, /placeholder=\{t\("lockout.ipPlaceholder"\)\}/);
  assert.match(source, /className="min-w-0 font-mono"/);
  assert.match(source, /aria-describedby=\{draftError \? "f2b-ignore-examples f2b-ignore-hint f2b-ignore-error" : "f2b-ignore-examples f2b-ignore-hint"\}/);
  assert.match(source, /id="f2b-ignore-examples">\{t\("settings.ipPlaceholder"\)\}/);
  assert.match(source, /id="f2b-ignore-error" role="alert"/);
  assert.doesNotMatch(source, /ipPlaceholder"\)\.(split|slice|match)/);
  const caution = section(source, '            <div data-slot="caution"', '          <div className="flex-none space-y-2">');
  assert.match(caution, /aria-label=\{t\("settings.addMine"\)\}/);
  assert.match(caution, /\{t\("settings.addIp"\)\}/);
  assert.match(caution, /disabled=\{!canManage \|\| adding\}/);
  assert.match(caution, /onClick=\{\(\) => add\(yourIp\)\}/);
  for (const locale of locales) {
    const f = JSON.parse(read('messages/' + locale + '.json')).fail2ban;
    assert.equal(f.lockout.ipPlaceholder, "203.0.113.10", locale);
    assert.match(f.settings.ipPlaceholder, /203\.0\.113\.5.*203\.0\.113\.0\/24/, locale);
    assert.match(f.settings.yourIpMissing, /\{ip\}/, locale);
    assert.equal((f.settings.yourIpMissing.match(/\{ip\}/g) ?? []).length, 1, locale);
    assert.ok(f.settings.yourIpMissing.length < 110, locale + ': concise notice');
  }
});

test("actual rendered toolbar DOM respects Refresh/log/Unban all/Ban under every conditional combination", () => {
  const header = section(card("banned-card"), "      <CardHeader", "      <CardContent").trim();
  const { transformSync } = require("next/dist/build/swc");
  const code = transformSync('module.exports = () => (' + header + ');', {
    filename: "fail2ban-toolbar-fixture.jsx",
    jsc: { parser: { syntax: "ecmascript", jsx: true }, transform: { react: { runtime: "automatic" } } },
    module: { type: "commonjs" },
  }).code;
  const div = ({ children, ...props }) => React.createElement("div", props, children);
  const Button = ({children, asChild, variant, ...props}) => asChild ? children : React.createElement("button", props, children);
  for (const logHref of [null, "/logs?source=fail2ban"]) for (const count of [0, 3]) for (const canManage of [false, true]) {
    const context = {
      require, module: { exports: {} }, CardHeader: div, CardTitle: div, CardDescription: div, Button,
      Link: ({children, ...props}) => React.createElement("a", props, children),
      ScrollText: () => null, ReasonTooltip: ({children}) => children,
      RefreshButton: () => React.createElement("button", {}, "Refresh"),
      BanIpDialog: ({canManage}) => React.createElement("button", {disabled: !canManage}, "Ban"),
      logHref, banned: Array(count), canManage, jails: [], yourIp: null, serverIp: null,
      setConfirmAll: () => {}, t: (key) => key,
    };
    context.exports = context.module.exports;
    vm.runInNewContext(code, context);
    const html = renderToStaticMarkup(React.createElement(context.module.exports));
    const controls = [...html.matchAll(/<(button|a)\b[^>]*>(.*?)<\/(?:button|a)>/gs)].map(match => match[2]);
    assert.deepEqual(controls, ["Refresh", ...(logHref ? ["banned.seeLog"] : []), ...(count ? ["banned.unbanAll"] : []), "Ban"]);
    assert.equal((html.match(/ disabled=""/g) ?? []).length, canManage ? 0 : count ? 2 : 1);
  }
});
