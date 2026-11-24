import { afterEach, before, test } from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";
import { installDom, loadComponent } from "./support/dom.mjs";

installDom();
const { createElement } = await import("react");
const { cleanup, fireEvent, render, screen, waitFor } = await import("@testing-library/react");
const { IntlProvider } = await import("use-intl");
const rule = await import("../lib/applications/fail2ban-login-rule.js");
const fields = await import("../lib/applications/fail2ban-jail-fields.js");
const read = (p) => readFileSync(p, "utf8");
const messages = JSON.parse(read("messages/en.json"));
const LOCALES = ["en", "es", "de", "fr", "hi", "pt", "ja", "ru"];

let Fail2banPanel;
let TooltipProvider;
const savedConfigs = () => globalThis.__fail2banSaved ?? [];
before(async () => {
  const stubs = {
    "@/lib/api/applications": "tests/support/app-fail2ban-api-stub.js",
    "@/hooks/use-refresh": "tests/support/use-refresh-stub.js",
    "next/navigation": "tests/support/next-navigation-stub.js",
    "next/dynamic": "tests/support/next-dynamic-stub.jsx",
  };
  ({ Fail2banPanel } = await loadComponent("components/applications/fail2ban/fail2ban-panel.jsx", stubs));
  ({ TooltipProvider } = await loadComponent("components/ui/tooltip.jsx"));
});
afterEach(cleanup);

const JAIL = "[panel-site-x]\nenabled  = true\nfilter   = panel-site-x\nlogpath  = /home/u/x/logs/access*log\nmaxretry = 3\nbantime  = 3600\nfindtime = 600\n";
const WP_FILTER = '[Definition]\nfailregex = ^<HOST> .* "POST [^"]*wp-login\\.php[^"]*" 200\\b\nignoreregex =\n';

function renderPanel({ config = { jail_name: "panel-site-x", jail_content: JAIL, filter_content: WP_FILTER }, loginRuleOffered = true } = {}) {
  render(
    createElement(
      IntlProvider,
      { locale: "en", messages, timeZone: "UTC" },
      createElement(
        TooltipProvider,
        null,
        createElement(Fail2banPanel, { appId: 9, config, jailTemplate: JAIL, filterTemplate: WP_FILTER, canManage: true, loginRuleOffered }),
      ),
    ),
  );
}

// 24 Nov (Krishna): the second user pass, then "too much at once" on the app Fail2ban page.
test("a login-page rule counts only the chosen wrong-password answer on that exact path", () => {
  const filter = rule.loginFilter("/admin/login.php", "error");
  assert.match(filter, /^\[Definition\]$/m);
  assert.match(filter, /"POST \/admin\/login\\\.php\(\?:\\\?\[\^"\]\*\)\? HTTP\/\[\^"\]\*" \(\?:401\|403\|422\|429\)\\b/);
  assert.doesNotMatch(filter, /wp-login|xmlrpc/);
  assert.match(rule.loginFilter("/login", "page"), /" 200\\b$/m);
  assert.deepEqual(rule.parseLoginRule(filter), { path: "/admin/login.php", answer: "error" });
  assert.equal(rule.parseLoginRule("[Definition]\nfailregex = x\n"), null);
});

test("a login path may not carry what fail2ban or the backend would rewrite", () => {
  for (const ok of ["/login", "/admin/login.php", "/user/sign_in", "/"]) assert.equal(rule.validLoginPath(ok), true, ok);
  for (const bad of ["", "login", "/log in", "/a%b", "/{name}", '/x"', "/a?b=1"]) assert.equal(rule.validLoginPath(bad), false, bad);
});

test("the jail's numbers are read and written in place, keeping the file's spacing", () => {
  assert.equal(fields.readJailValue(JAIL, "bantime"), "3600");
  assert.equal(fields.readJailValue("[x]\nenabled = true\n", "maxretry"), null);
  const next = fields.writeJailValue(JAIL, "maxretry", "5");
  assert.match(next, /^maxretry = 5$/m);
  assert.equal(next.replace("maxretry = 5", "maxretry = 3"), JAIL);
});

test("a site with the WordPress rule says nobody is banned, and a login page fixes it", async () => {
  renderPanel();
  assert.ok(screen.getByText("No login page is set, so nobody is banned yet. Add it below."));
  // The raw files are folded away until asked for.
  assert.equal(screen.queryByLabelText("filter.conf", { selector: "textarea" }), null);
  const save = () => screen.getByRole("button", { name: "Save and reload fail2ban" });
  assert.equal(save().disabled, true);

  fireEvent.change(screen.getByLabelText("Login page address"), { target: { value: "/login" } });
  assert.equal(save().disabled, false);
  fireEvent.click(save());
  await waitFor(() => assert.equal(savedConfigs().length, 1));
  assert.equal(savedConfigs()[0].filter, rule.loginFilter("/login", "error"));
  assert.equal(savedConfigs()[0].jail, JAIL);
  await waitFor(() => assert.ok(screen.getByText("Banning addresses that get the password wrong at /login.")));
  assert.equal(screen.queryByText(/nobody is banned yet/), null);
});

test("the numbers edit the jail, and a wrong one blocks Save with the reason", () => {
  renderPanel({ config: { jail_name: "panel-site-x", jail_content: JAIL, filter_content: rule.loginFilter("/login", "error") } });
  assert.equal(screen.getByLabelText("Login page address").value, "/login");
  assert.match(document.body.textContent, /3 wrong tries in 10 minutes would ban an address for 1 hour/);

  fireEvent.change(screen.getByLabelText("Wrong tries allowed"), { target: { value: "5" } });
  assert.match(document.body.textContent, /5 wrong tries in 10 minutes/);
  fireEvent.click(screen.getByRole("button", { name: "Edit the files yourself" }));
  assert.match(screen.getByLabelText("jail.conf", { selector: "textarea" }).value, /^maxretry = 5$/m);

  fireEvent.change(screen.getByLabelText("Wrong tries allowed"), { target: { value: "0" } });
  assert.equal(screen.getByRole("button", { name: "Save and reload fail2ban" }).disabled, true);
  assert.ok(screen.getAllByText("Use a whole number from 1 to 100.").length > 0);
});

test("turning it on for a non-WordPress site waits for the login page", () => {
  renderPanel({ config: null });
  fireEvent.click(screen.getByRole("button", { name: "Set up Fail2ban" }));
  assert.ok(screen.getByText("Check the rules below, then turn it on."));
  assert.equal(screen.getByRole("button", { name: "Turn on Fail2ban" }).disabled, true);
  assert.ok(screen.getAllByText("Enter the login page address first.").length > 0);
  fireEvent.change(screen.getByLabelText("Login page address"), { target: { value: "/user/login" } });
  assert.equal(screen.getByRole("button", { name: "Turn on Fail2ban" }).disabled, false);
});

test("a WordPress site gets no login field and says what it watches", () => {
  renderPanel({ loginRuleOffered: false });
  assert.equal(screen.queryByLabelText("Login page address"), null);
  assert.ok(screen.getByText("Banning addresses that get the WordPress password wrong."));
  assert.ok(screen.getByLabelText("Banned for"));
});

test("a hand-written filter is left alone by the login field", () => {
  renderPanel({ config: { jail_name: "panel-site-x", jail_content: JAIL, filter_content: rule.loginFilter("/login", "error") } });
  fireEvent.click(screen.getByRole("button", { name: "Edit the files yourself" }));
  const own = "[Definition]\nfailregex = ^<HOST> .* \"POST /signin\" 403\nignoreregex =\n";
  fireEvent.change(screen.getByLabelText("filter.conf", { selector: "textarea" }), { target: { value: own } });
  assert.equal(screen.getByLabelText("Login page address").value, "");
  assert.equal(screen.getByLabelText("filter.conf", { selector: "textarea" }).value, own);
  assert.equal(screen.getByRole("button", { name: "Save and reload fail2ban" }).disabled, false);
});

test("Disk Cleaner says what it freed in a toast as well as the note", () => {
  const panel = read("components/disk-cleaner/cleanup-panel.jsx");
  assert.match(panel, /setResult\(parsed\.data\);\s*\/\/[^\n]*\n\s*toast\.success\(\s*t\("clean\.freed"/);
});

test("Server Sync counts only what can be added", () => {
  assert.match(read("components/sync/sync-panel.jsx"), /addable=\{items\.filter\(\(item\) => item\.action === "found" && !ignoredKeys\.has\(ignoreKey\(item\)\)\)\.length\}/);
  assert.match(read("components/sync/sync-summary.jsx"), /t\("summary\.previewAddable", \{ count: addable \}\)/);
});

test("the clone screen breaks a domain only after a dot", () => {
  const clone = read("components/applications/clone/clone-progress.jsx");
  assert.match(clone, /<BreakableDomain domain=/);
  assert.doesNotMatch(clone, /break-all/);
});

test("every new string exists in all 8 languages, with its placeholders", () => {
  for (const locale of LOCALES) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    const f = m.applications.fail2ban;
    for (const key of ["pathLabel", "pathHint", "answerLabel", "answerHint"]) assert.ok(f.loginRule[key], `${locale} ${key}`);
    assert.ok(f.loginRule.answers.error && f.loginRule.answers.page && f.loginRule.errors.required && f.loginRule.errors.invalid, locale);
    assert.ok(f.rules.title && f.rules.description && f.rules.errors.maxretry && f.rules.errors.findtime && f.files.toggle, locale);
    for (const key of ["setupBody", "onNoRule", "onWordPress", "onCustom"]) assert.ok(f.state[key], `${locale} ${key}`);
    assert.match(f.state.onPath, /\{path\}/, locale);
    assert.match(m.sync.summary.previewAddable, /=0 \{/, locale);
    assert.match(m.docker.registries.confirm.title, /\{name\}/, locale);
    assert.match(m.applications.php.budget.equation, /\{workers\}/, locale);
    if (locale !== "en") assert.doesNotMatch(m.applications.php.budget.equation, /\bworkers\b(?! *\})/i, locale);
  }
});

test("a short domain label is kept whole; only a label too long for a line may break", async () => {
  const { BreakableDomain } = await loadComponent("components/ui/breakable-domain.jsx");
  const { container } = render(createElement("p", null, createElement(BreakableDomain, { domain: "ux-clone-with-a-much-longer-subdomain-name-1124.23-172-120-111.nip.io" })));
  const spans = [...container.querySelectorAll("p > span")];
  assert.deepEqual(spans.map((s) => s.textContent), ["ux-clone-with-a-much-longer-subdomain-name-1124.", "23-172-120-111.", "nip.", "io"]);
  assert.deepEqual(spans.map((s) => s.className), ["", "whitespace-nowrap", "whitespace-nowrap", "whitespace-nowrap"]);
});
