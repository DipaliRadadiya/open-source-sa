import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const dialog = read("components/applications/magic-login-dialog.jsx");
const launcher = read("components/applications/magic-login-launcher.jsx");
/*
 * The tab handling moved out of the dialog when magic login gained a second
 * way in: one administrator now signs straight through, several still pick.
 * The care below is shared by both paths and is the kind that gets half-copied,
 * so it lives in one file and is asserted against that file.
 */
const tabs = read("lib/applications/magic-login-window.js");
// Opening, painting and discarding the tab are shared with phpMyAdmin — same
// constraint, same care, and it was being half-copied between the two.
const newTab = read("lib/browser/new-tab.js");
const hook = read("components/applications/use-magic-login.js");
const page = read("app/(app)/applications/[application]/page.jsx");
const navigation = read("lib/navigation.js");

const { locales } = await import("../i18n/routing.js");

/**
 * Source with comments removed.
 *
 * A "this pattern must not appear" assertion against raw source reads prose as
 * if it were code — the first version of the site-type test below failed on the
 * comment explaining why the site-type check is deliberately absent.
 */
function code(source) {
  return source
    .replace(/\/\*[\s\S]*?\*\//g, "")
    .replace(/(^|[^:])\/\/.*$/gm, "$1");
}

test("the token is posted, never put in a URL", () => {
  // A token in a query string is written to the site's access log, the
  // browser's history and any outbound Referer — and this one buys a full
  // administrator session. The legacy product put it in the URL.
  assert.match(newTab, /form\.method = "POST"/);
  assert.match(tabs, /postInNewTab\(session\.url, \{ sv_magic_login: session\.token \}\)/);
  assert.doesNotMatch(code(tabs), /sv_magic_login=/);
  assert.doesNotMatch(code(tabs), /\?.*token/i);
  // And no caller may reach for the URL form instead.
  for (const source of [dialog, hook]) {
    assert.doesNotMatch(code(source), /window\.open\([^)]*token/i);
  }
});

test("the form is built through the DOM, not written as HTML", () => {
  // Interpolating the token and the site URL into markup is injection-shaped.
  // "The token is alphanumeric" stops being true the day the format changes.
  assert.match(newTab, /createElement\("form"\)/);
  assert.match(newTab, /createElement\("input"\)/);
  assert.doesNotMatch(newTab, /document\.write/);
  assert.doesNotMatch(newTab, /innerHTML/);
});

test("the opened tab cannot reach back into the panel", () => {
  // `noopener` in the feature string would make window.open return null and
  // cost us the handle the form needs, so the opener is severed by hand.
  assert.match(newTab, /tab\.opener = null/);
  assert.match(newTab, /form\.rel = "noopener"/);
});

test("the button is gated by the permission, not by a hardcoded site type", () => {
  // `app_magic_login` exists only in WordPressSiteType::features(), and
  // VisiblePermissions filters the catalog by the site's features — so the
  // permission IS the site-type gate. A second check here would duplicate the
  // decision in the place the backend comment says not to put it.
  assert.match(
    page,
    /can\(appPermissions, "app_magic_login", "manage", "application"\)/,
  );
  assert.match(page, /canMagicLogin && application\.status === "active"/);
  assert.doesNotMatch(code(page), /site_type === "wordpress"/);
});

test("the administrator list is never reused across opens", () => {
  /*
   * An account that was an administrator last time may not be one now, and a
   * stale name offers a refusal the user cannot explain.
   *
   * This used to be enforced with a remount key. It is structural now: the
   * dialog is mounted only while `choice` is set, `choice` is set only by the
   * fetch inside `start()`, and it is cleared on close — so there is no list
   * that can outlive an open.
   */
  assert.match(hook, /getWordPressAdministrators\(appId\)/);
  assert.match(hook, /setChoice\(\{ admins \}\)/);
  assert.match(hook, /closeChoice: useCallback\(\(\) => setChoice\(null\)/);

  for (const source of [launcher, read("components/applications/application-row-actions.jsx")]) {
    assert.match(source, /choice \? \(\s*<MagicLoginDialog/);
  }

  // And the dialog must not have grown a fetch of its own again.
  assert.doesNotMatch(dialog, /getWordPressAdministrators/);
});

test("one administrator signs straight in; none or several still pick", () => {
  /*
   * The picker asked the operator of a single-administrator site to choose
   * from a list of one, then click again.
   *
   * Zero is deliberately NOT merged into the error path: "this site has no
   * administrators" and "we could not ask WordPress" look identical as an
   * empty list, and only one of them is the operator's problem.
   */
  assert.match(hook, /if \(admins\.length === 1\)/);
  assert.match(hook, /createMagicLogin\(appId, admins\[0\]\.id\)/);
  assert.match(hook, /launchMagicLogin\(session\)/);
  assert.match(dialog, /admins\.length === 0 \?/);
});

test("the button says which wait it is: fetching users, then signing in (Krishna, 2026-09-29)", () => {
  assert.match(hook, /setPhase\("fetching"\);\s*try \{\s*const admins = await getWordPressAdministrators/);
  assert.match(hook, /if \(admins\.length === 1\) \{\s*setPhase\("signing"\);/);
});

test("no tab opens until the login link exists (Krishna, 2026-09-29)", () => {
  /*
   * The old flow opened about:blank on the click and held it through the
   * WP-CLI round trip — a blank page for seconds, and with several
   * administrators it was closed again to show the picker. Now the button
   * carries the wait and the tab opens straight onto WordPress.
   */
  for (const [name, source] of [["hook", hook], ["dialog", dialog]]) {
    const body = code(source);
    assert.doesNotMatch(body, /openBlankTab|paintPlaceholder|discardTab/, `${name} still opens a blank tab`);
    assert.ok(body.lastIndexOf("launchMagicLogin(session)") > body.indexOf("await createMagicLogin"), `${name} opens before the link is ready`);
  }
  // No fallback toast with a second "Open" button (Krishna, 2026-09-30).
  assert.doesNotMatch(hook, /openAdmin|linkReady/);
  assert.doesNotMatch(newTab, /canOpenTab|userActivation/);
  // Krishna 2026-09-29 (second report): not even an empty tab filled in later —
  // the form posts straight into a new tab.
  assert.match(newTab, /form\.target = "_blank"/);
  assert.doesNotMatch(newTab, /window\.open\(""/);
  // The token still goes by POST, never in a URL.
  assert.match(read("lib/applications/magic-login-window.js"), /return postInNewTab\(session\.url, \{ sv_magic_login: session\.token \}\);/);
});

test("a permission with no url is kept out of the sidebar", () => {
  // Magic Login is the first permission that is not a screen. Without this
  // filter the sidebar renders it as a dead "not built yet" row.
  assert.match(navigation, /item\.url !== null && item\.url !== undefined/);
  // "" is the Dashboard, whose href is the application root — so the check has
  // to be for null specifically, never for falsiness.
  assert.doesNotMatch(navigation, /\.filter\(\(item\) => item\.url\)/);
});

test("every string exists in every locale", () => {
  const keys = [
    // "loading" is gone: the dialog no longer waits for the list — it arrives
    // already fetched, and the spinner moved to the button that fetched it.
    "action", "title", "subtitle", "signIn", "cancel", "none",
    "listFailed", "failed", "redirecting", "auditNote", "fetchingUsers",
  ];

  for (const locale of locales) {
    const messages = JSON.parse(read(`messages/${locale}.json`));
    const group = messages.applications?.magicLogin;

    assert.ok(group, `${locale}: applications.magicLogin must exist`);

    for (const key of keys) {
      assert.equal(typeof group[key], "string", `${locale}: magicLogin.${key} must exist`);
      assert.ok(group[key].length > 0, `${locale}: magicLogin.${key} must not be empty`);
    }
  }
});

test("the dialog says the login is recorded", () => {
  // This is impersonation. Someone reading the activity log later should not
  // be the first person to learn that it names who they signed in as.
  assert.match(dialog, /auditNote/);
  const en = JSON.parse(read("messages/en.json"));
  assert.match(en.applications.magicLogin.auditNote, /activity log/i);
  assert.match(en.applications.magicLogin.auditNote, /once|expires/i);
});
