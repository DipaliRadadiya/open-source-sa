import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * The Deployments screen, rebuilt from five stacked cards into a hero plus
 * tabs. Most of what is locked here is not layout taste — it is the handful of
 * things that, if quietly undone, break something a reader would only find at
 * the worst moment.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const { locales } = await import("../i18n/routing.js");
const DIR = "components/applications/deployment/";
const panel = read(`${DIR}deployment-panel.jsx`);
const settingsCard = read(`${DIR}deploy-settings-card.jsx`);
const runtimeCard = read(`${DIR}runtime-card.jsx`);
const webhookCard = read(`${DIR}webhook-card.jsx`);
const deployCard = read(`${DIR}deploy-card.jsx`);
const historyCard = read(`${DIR}deploy-history-card.jsx`);

const code = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/\/\/.*$/gm, "");

test("every tab panel is forceMount, which the build-log link depends on", () => {
  /*
   * The hero's failure banner opens a build log that lives inside the history
   * card. `showBuildLog` switches tab and calls the ref in the same breath —
   * which only works because the card is mounted whichever tab is showing.
   * Drop forceMount and the ref is null on every tab but History, so the one
   * control you press when a deploy has just failed does nothing at all.
   */
  const contents = code(panel).match(/<TabsContent/g) ?? [];
  const forced = code(panel).match(/<TabsContent[^>]*forceMount/g) ?? [];
  assert.equal(contents.length, 3, "three tabs");
  assert.equal(forced.length, contents.length, "all of them forceMount");
});

test("switching tab and opening the log happen together, not via an effect", () => {
  // The first version parked the deployment in state and opened it from an
  // effect once the tab changed — a `set-state-in-effect` lint error, and a
  // dance around a problem forceMount had already solved.
  const fn = code(panel).match(/showBuildLog = useCallback\(([\s\S]*?)\}, \[setTab\]\)/);
  assert.ok(fn, "showBuildLog is a single callback");
  assert.match(fn[1], /setTab\("history"\)/);
  assert.match(fn[1], /historyRef\.current\?\.show\(/);
  assert.doesNotMatch(code(panel), /pendingLog/);
});

test("the deploy button is not inside a tab", () => {
  // What is deployed, and the button that changes it, must be true on every
  // tab. Putting the hero in a tab would hide the page's whole purpose behind
  // a click.
  const beforeTabs = code(panel).slice(0, code(panel).indexOf("<Tabs"));
  assert.match(beforeTabs, /<DeployCard/);
});

test("History is the tab you land on", () => {
  // It is what you want the second after pressing Deploy; it used to be a
  // thousand pixels below the button.
  // …unless the address names another tab: a reload keeps the one you were on.
  assert.match(code(panel), /TABS\.includes\(searchParams\.get\("tab"\)\) \? searchParams\.get\("tab"\) : "history"/);
});

test("the settings card has a heading of its own", () => {
  /*
   * It had none — the only card on the page without one — so the first field's
   * label, "Branch", acted as the section name for a card that also holds the
   * deploy script and its own Save.
   */
  assert.match(settingsCard, /title=\{t\("title"\)\}/);
  for (const locale of locales) {
    const s = JSON.parse(read(`messages/${locale}.json`)).applications.deployment.settings;
    assert.equal(typeof s.title, "string", `${locale} settings.title`);
    assert.equal(typeof s.subtitle, "string", `${locale} settings.subtitle`);
  }
});

test("the settings subtree kept all its field labels", () => {
  /*
   * Writing `settings = { title, subtitle }` instead of merging wiped all 17
   * of them, and the form rendered "Branch / Error / Script hint / Save" —
   * next-intl falling back to the last segment of each missing key. It builds
   * clean and looks like a different bug entirely.
   */
  const NEEDED = ["branch", "branchHint", "script", "scriptHint", "placeholders", "resetScript", "save", "branchNotice"];
  for (const locale of locales) {
    const s = JSON.parse(read(`messages/${locale}.json`)).applications.deployment.settings;
    for (const key of NEEDED) {
      assert.ok(s[key], `${locale} lost settings.${key}`);
    }
  }
});

test("both settings cards use the panel's Section/Row system", () => {
  // Not hand-rolled Cards. The tab had three field layouts before this: house
  // rows in one card, a bespoke two-column grid in the other, and full width
  // inside that.
  for (const [name, source] of [["settings", settingsCard], ["runtime", runtimeCard]]) {
    assert.match(source, /from "@\/components\/settings\/setting-row"/, `${name} imports the house rows`);
    assert.doesNotMatch(code(source), /<Card[ >]/, `${name} still hand-rolls a Card`);
  }
});

test("every field is full width", () => {
  // Krishna's call, after seeing the mixed version: one layout, no exceptions.
  for (const [name, source] of [["settings", settingsCard], ["runtime", runtimeCard]]) {
    const rows = code(source).match(/<Row\b[\s\S]{0,200}?>/g) ?? [];
    assert.ok(rows.length, `${name} has rows`);
    for (const row of rows) {
      assert.match(row, /\bwide\b/, `${name} has a row that is not full width: ${row.slice(0, 60)}`);
    }
  }
});

test("every card on the page wears the panel's chrome", () => {
  // They hand-rolled `shadow-sm` with a different ring while the dashboard
  // cards used PANEL_CARD — which is why the page looked like another product.
  // Section supplies its own, so the two settings cards are exempt.
  for (const [name, source] of [["deploy", deployCard], ["webhook", webhookCard], ["history", historyCard]]) {
    assert.match(source, /PANEL_CARD/, `${name} card`);
  }
});

test("the webhook setup steps are not shown before there is anything to paste", () => {
  /*
   * They said "paste the URL below, paste the secret into Secret" while no URL
   * and no secret existed — neither is created until Enable is pressed. The
   * steps were moved to the configured state, beside the values they name.
   */
  // Anchored on the RENDER branch, not the first textual match — the first
  // "providers.length ?" is inside a useState initialiser near the top.
  const src = code(webhookCard);
  const split = src.indexOf(") : providers.length ?");
  assert.ok(split > 0, "found the not-configured branch");
  const notConfigured = src.slice(split);
  const enabledBlock = src.slice(0, split);
  assert.match(enabledBlock, /<Instructions/, "still shown once configured");
  assert.doesNotMatch(notConfigured, /<Instructions/, "not shown before setup");
});

test("the control that turns deploy-on-push on sits in one place", () => {
  // Enable was bottom-left in the body while the switch was top-right in the
  // header, so the same job moved across the card depending on a state the
  // reader cannot see.
  const actions = code(webhookCard).match(/<CardAction[\s\S]*?<\/CardAction>/g) ?? [];
  assert.equal(actions.length, 2, "one for the switch, one for Enable");
  assert.ok(actions.some((a) => /Switch/.test(a)), "configured: switch");
  assert.ok(actions.some((a) => /webhook\.enable/.test(a)), "not configured: Enable");
});

test("a branch we could not list is not reported as a failure", () => {
  // `error` and `empty` describe a fallback the card already applied and the
  // field still works. In red they read as though the save had broken. Only
  // `unlinked` — no working Git account — needs the reader to go fix something.
  const hint = settingsCard.match(/hint=\{notice && notice !== "unlinked"([^\n]*)/);
  const error = settingsCard.match(/error=\{notice === "unlinked"([^\n]*)/);
  assert.ok(hint, "non-fatal notices are hints");
  assert.ok(error, "unlinked goes to Row's destructive error slot");
});

test("the tab labels reuse the words the panel already had", () => {
  /*
   * "History" and "Settings" both existed elsewhere before this page needed
   * them. Inventing a second translation is the mistake the one-voice guard
   * caught seven times over on "Needs attention".
   */
  const en = JSON.parse(read("messages/en.json"));
  const find = (m, word) => {
    let found = null;
    (function walk(node, path) {
      for (const key in node) {
        const value = node[key];
        const q = path ? `${path}.${key}` : key;
        if (typeof value === "string") {
          const source = q.split(".").reduce((a, k) => a?.[k], en);
          if (!found && source === word && q !== `applications.deployment.tabs.${word.toLowerCase()}`) found = value;
        } else if (value && typeof value === "object") walk(value, q);
      }
    })(m, "");
    return found;
  };

  for (const locale of locales) {
    const m = JSON.parse(read(`messages/${locale}.json`));
    const tabs = m.applications.deployment.tabs;
    assert.equal(typeof tabs.automation, "string", `${locale} automation`);
    for (const word of ["History", "Settings"]) {
      const existing = find(m, word);
      if (!existing) continue;
      assert.equal(tabs[word.toLowerCase()], existing, `${locale} says "${word}" two ways`);
    }
  }
});

test("a token can be dropped into the script instead of typed", () => {
  // `{path}` has to be exact, and reading it off the screen to retype it is the
  // one place on this card a typo costs a failed deploy.
  assert.match(settingsCard, /function insertToken/);
  assert.match(settingsCard, /setSelectionRange/);
  assert.match(settingsCard, /onInsert=\{canManage && !saving \? insertToken : null\}/);
});
