import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import test from "node:test";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const dialog = read("components/applications/magic-login-dialog.jsx");
const launcher = read("components/applications/magic-login-launcher.jsx");
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
  assert.match(dialog, /form\.method = "POST"/);
  assert.match(dialog, /field\.name = "sv_magic_login"/);
  assert.doesNotMatch(dialog, /sv_magic_login=/);
  assert.doesNotMatch(dialog, /\?.*token/i);
});

test("the form is built through the DOM, not written as HTML", () => {
  // Interpolating the token and the site URL into markup is injection-shaped.
  // "The token is alphanumeric" stops being true the day the format changes.
  assert.match(dialog, /createElement\("form"\)/);
  assert.match(dialog, /createElement\("input"\)/);
  assert.doesNotMatch(dialog, /document\.write/);
  assert.doesNotMatch(dialog, /innerHTML/);
});

test("the opened tab cannot reach back into the panel", () => {
  // `noopener` in the feature string would make window.open return null and
  // cost us the handle the form needs, so the opener is severed by hand.
  assert.match(dialog, /target\.opener = null/);
  assert.doesNotMatch(dialog, /window\.open\([^)]*noopener/);
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
  // An account that was an administrator last time may not be one now, and a
  // stale name offers a refusal the user cannot explain.
  assert.match(launcher, /setRun\(\(n\) => n \+ 1\)/);
  assert.match(launcher, /key=\{run\}/);
  assert.doesNotMatch(dialog, /setAdmins\(null\);/);
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
    "action", "title", "subtitle", "signIn", "cancel", "loading", "none",
    "listFailed", "failed", "popupBlocked", "redirecting", "auditNote",
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
