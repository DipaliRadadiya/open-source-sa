// 5 Nov, a beta tester's dashboard feedback (Krishna approved items 1–7).
import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

test("the status bar is compact: a small drawing, no greeting, a small headline", () => {
  const hero = read("components/dashboard/dashboard-hero.jsx");
  assert.doesNotMatch(hero, /greeting/);
  // The green drafting corners stay (Krishna, 5 Nov).
  assert.match(hero, /absolute top-1\.5 left-1\.5 size-4 border-t-2 border-l-2 border-primary/);
  assert.match(hero, /<PanelServer[\s\S]{0,200}hidden h-16 w-auto shrink-0 sm:block/);
  assert.match(hero, /<h1 className="flex items-center gap-2 text-lg font-semibold/);
});

test("the firewall tile switches it on and off through the Firewall page's confirmations", () => {
  const sw = read("components/dashboard/firewall-quick-switch.jsx");
  assert.match(sw, /onCheckedChange=\{\(next\) => setConfirming\(next \? "on" : "off"\)\}/);
  assert.match(sw, /title=\{t\("confirmOn\.title"\)\}/);
  assert.match(sw, /title=\{t\("confirmOff\.title"\)\}/);
  assert.match(sw, /toggleFirewall\(next\)/);
  assert.match(sw, /!canManage \? t\("disabled\.noPermission"\) : unknown \? t\("status\.unknownTitle"\) : null/);
  assert.match(read("app/(app)/dashboard/page.jsx"), /canManageFirewall=\{can\(permissions, "firewall", "manage"\)\}/);
});

test("counts and the firewall sit under the status bar, each only for who may open it", () => {
  const page = read("app/(app)/dashboard/page.jsx");
  assert.match(page, /getDashboardOverview\(\{\s*databases: canViewDatabases,\s*cronjobs: can\(permissions, "cronjob", "view"\),\s*systemUsers: can\(permissions, "system_user", "view"\),\s*firewall: can\(permissions, "firewall", "view"\),/);
  const overview = read("lib/dashboard/get-overview.js");
  // The smallest page the API accepts (1 is a 422); an unread count is null ("—"), never 0.
  assert.match(overview, /searchParams: \{ per_page: 10 \}/);
  assert.match(overview, /return result\.failed \? null :/);
  assert.match(overview, /\{ state: "unknown", rules: null, enabled: null \}/);
  const tiles = read("components/dashboard/dashboard-overview.jsx");
  for (const href of ["/applications", "/databases", "/cron-jobs", "/system-users", "/firewall"]) assert.match(tiles, new RegExp(`href: "${href}"`));
  assert.match(tiles, /off: \{ icon: ShieldOff, tone: "destructive", value: "text-destructive" \}/);
});

test("metric chips only when a reading needs a look; labels in normal case", () => {
  const tiles = read("components/dashboard/stat-cards.jsx");
  assert.match(tiles, /status\.key !== "normal" && status\.key !== "unknown"/);
  assert.match(tiles, /<p className="text-\[13px\] font-medium break-words text-muted-foreground">\{label\}<\/p>/);
  assert.match(read("components/dashboard/server-info-card.jsx"), /<dt className="text-\[13px\] font-medium text-muted-foreground">/);
  assert.doesNotMatch(read("app/globals.css"), /\[data-sidebar="group-label"\],\s*\[data-slot="breadcrumb-list"\]/);
});

test("nothing cut: server name, CPU model and the services link", () => {
  // One line, cut at the end, the whole name on hover over the card and in the status bar.
  const card = read("components/sections/sidebar-server-card.jsx");
  assert.match(card, /<HoverHint content=\{identity\.hostname\} side="right">\s*<Link/);
  assert.match(card, /block truncate font-mono text-sm font-semibold">\{identity\.hostname\}/);
  // The whole name is the breadcrumb's root (Krishna, 5 Nov), in normal case, with a hover hint.
  const crumbs = read("components/sections/app-breadcrumb.jsx");
  assert.match(crumbs, /label: identity\?\.hostname \?\? t\("breadcrumbServer"\)/);
  assert.match(crumbs, /const HOSTNAME = "max-w-48 normal-case tracking-normal sm:max-w-64";/);
  assert.match(crumbs, /<HoverHint content=\{item\.hostname \? item\.label : null\}>/);
  assert.doesNotMatch(read("components/dashboard/dashboard-hero.jsx"), /facts\?\.hostname/);
  const info = read("components/dashboard/server-info-card.jsx");
  assert.match(info, /text-sm font-medium break-words/);
  assert.match(info, /<HoverHint content=\{\(health\.names \?\? \[\]\)\.join\(", "\)\}>\s*<Badge asChild variant="success" className="gap-1\.5 py-1 font-medium whitespace-nowrap hover:underline">/);
});

test("the owner's username and password copy from the application page, password only for managers", () => {
  const facts = read("components/applications/site-facts-card.jsx");
  assert.match(facts, /if \(!canManageSystemUsers \|\| !ownerId\) return undefined;/);
  assert.match(facts, /getSystemUserDetail\(ownerId\)/);
  assert.match(facts, /icon: KeyRound/);
  // None saved: greyed with the reason; unread: left out rather than claimed as none.
  assert.match(facts, /reason: ownerPassword \? null : t\("facts\.noPasswordSaved"\)/);
  assert.match(facts, /useState\(undefined\)/);
  assert.match(read("app/(app)/applications/[application]/page.jsx"), /canManageSystemUsers=\{can\(permissions, "system_user", "manage"\)\}/);
});

test("sidebar card: the IP on its own line with a copy button outside the card's link; firewall Off stands alone", () => {
  const card = read("components/sections/sidebar-server-card.jsx");
  assert.doesNotMatch(card, /· \{identity\.ip\}/);
  assert.match(card, /<CopyButton value=\{identity\.ip\} label=\{td\("info\.copyIp"\)\} className="relative z-20/);
  assert.match(card, /className="absolute inset-0 z-10 rounded-\[2px\]/);
  const tiles = read("components/dashboard/dashboard-overview.jsx");
  assert.match(tiles, /sub: state === "on" && rules !== null \? t\("firewallRules", \{ count: rules \}\) : null,/);
  assert.match(tiles, /hint: t\(`firewall\.\$\{state\}Hint`\),/);
});
