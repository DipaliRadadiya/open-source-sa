import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Krishna: "When a new Git account is connected, show a Create Application
 * option on the Git Integration page. On clicking it, redirect to Create
 * Application with Git-related options automatically selected."
 *
 * Half of it existed. The prompt was gated on `accounts.length === 0`, so it
 * only ever appeared for somebody's FIRST account — connect a second and the
 * page said nothing. And the link carried `?type=git` alone, so with two or
 * more accounts the create form opened with an empty picker, asking you to
 * find the account you made ten seconds earlier.
 *
 * Both halves driven in a browser: the prompt renders and names the account,
 * and arriving at the form with `git_account=12` opens it showing "Work
 * GitLab".
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
const card = strip(read("components/integrations/git/accounts-card.jsx"));
const form = strip(read("components/integrations/git/connect-form.jsx"));
const dialog = strip(read("components/integrations/git/connect-dialog.jsx"));
const createPage = strip(read("app/(app)/applications/create/page.jsx"));
const createForm = strip(read("components/applications/create-application-form.jsx"));
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);

test("every connect reports itself, not only the first", () => {
  // The gate is gone from both the caller and the dialog's plumbing.
  assert.doesNotMatch(card, /showNextStep/);
  assert.doesNotMatch(dialog, /showNextStep/);
  assert.doesNotMatch(form, /showNextStep/);
  assert.match(card, /onAccountConnected=\{setJustConnected\}/);
});

test("the account travels, not a boolean", () => {
  /*
   * With one account "your Git account is connected" was unambiguous. With
   * two it is not, and the link needs the id anyway, so the payload is the
   * account.
   *
   * `id` comes from the RESPONSE rather than the submitted form: only the
   * server knows it, and the link is useless without it.
   */
  assert.match(form, /const \{ data \} = await connectAccount\(payload\)/);
  assert.match(form, /id: data\?\.git_account\?\.id \?\? null/);
  assert.match(form, /label: values\.label/);
  assert.match(form, /provider: provider\.name/);
});

test("the prompt names the account and shows its mark", () => {
  assert.match(card, /t\("onboarding\.title", \{ label: justConnected\.label \}\)/);
  assert.match(card, /<ProviderLogo provider=\{justConnected\.provider\}/);
  for (const locale of LOCALES) {
    const title = JSON.parse(read(`messages/${locale}.json`)).git.onboarding.title;
    assert.match(title, /\{label\}/, `${locale} onboarding.title should name the account`);
  }
});

test("the link carries the account to the create form", () => {
  assert.match(card, /\?type=git&git_account=\$\{justConnected\.id\}/);
  // An account whose id did not come back still gets the old link rather than
  // `git_account=null`, which the page would drop anyway.
  assert.match(card, /: "\/applications\/create\?type=git"/);
});

test("the create page checks the id against the real accounts", () => {
  /*
   * Same discipline the page already applies to `?type=`: a query parameter is
   * somebody else's input. An unknown id would seed the picker with an account
   * that does not exist, leaving a form that cannot be submitted and a select
   * showing nothing.
   */
  assert.match(createPage, /const prefillGitAccount = \(accounts\.accounts \?\? \[\]\)\.some\(/);
  assert.match(createPage, /String\(account\.id\) === String\(sp\?\.git_account\)/);
  assert.match(createPage, /initialGitAccountId=\{prefillGitAccount\}/);
});

test("a named account beats the sole-account shortcut", () => {
  // The form already auto-picked when exactly one account existed. The URL has
  // to win, or arriving from the prompt with two accounts opens empty.
  assert.match(createForm, /const startingGitAccountId = initialGitAccountId \|\| soleGitAccountId/);
  assert.match(createForm, /git_account_id: startingGitAccountId/);
});

test("the prompt is tied to the connect, not to the page", () => {
  /*
   * State, so a refresh or a navigation clears it. Made permanent it would be
   * an advert on a page people visit to MANAGE accounts, with no way to
   * dismiss it.
   */
  assert.match(card, /const \[justConnected, setJustConnected\] = useState\(null\)/);
  assert.doesNotMatch(card, /localStorage|sessionStorage/);
});
