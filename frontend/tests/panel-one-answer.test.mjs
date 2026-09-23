import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Batch C: three places where the panel made you answer a question it could
 * answer itself, or offered an action it could not complete.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);

test("a single Git account is preselected, in both places that ask", () => {
  /*
   * One connected account meant opening a menu, choosing the only entry, and
   * only then being allowed to continue — in the relink dialog the Confirm
   * button was disabled until you did.
   */
  const relink = read("components/applications/relink-git-account-dialog.jsx");
  assert.match(relink, /const defaultAccountId = accounts\.length === 1 \? String\(accounts\[0\]\.id\) : ""/);
  assert.match(relink, /useState\(defaultAccountId\)/);

  const form = read("components/applications/create-application-form.jsx");
  assert.match(form, /const soleGitAccountId = gitAccounts\.length === 1 \? String\(gitAccounts\[0\]\.id\) : ""/);
  /*
   * The default is `startingGitAccountId` now, not `soleGitAccountId` — the
   * Git page can send you here naming the account it just connected, and that
   * has to win or arriving from its prompt with two accounts opens empty.
   *
   * Asserted as "the sole-account value still reaches the default" rather than
   * by pinning the name: the behaviour this test protects is the preselection,
   * and it is intact — `soleGitAccountId` is the fallback.
   */
  assert.match(form, /const startingGitAccountId = initialGitAccountId \|\| soleGitAccountId/);
  assert.match(form, /git_account_id: startingGitAccountId/);
});

test("the relink dialog's resets do not undo its own preselection", () => {
  /*
   * The dialog is not remounted between opens — it takes an `open` prop — so
   * resetting to "" on close or on success would put the picker back to
   * unanswered the second time it was opened.
   */
  const relink = read("components/applications/relink-git-account-dialog.jsx");
  const code = strip(relink);
  assert.doesNotMatch(code, /setAccountId\(""\)/, "a reset still clears to empty");
  assert.equal((code.match(/setAccountId\(defaultAccountId\)/g) ?? []).length, 2);
});

test("a preselected account does not leave the repository list looking idle", () => {
  // The fetch effect keys on a non-empty id, but only the change handler ever
  // set "loading" — so the picker read as idle while its request was in flight.
  const form = read("components/applications/create-application-form.jsx");
  assert.match(form, /useState\(\(\) =>\s*gitAccounts\.length === 1 \? "loading" : "idle",\s*\)/);
});

test("Issue is unavailable until both PEM blocks are present", () => {
  /*
   * It was enabled with both boxes empty, so pressing it spent a round trip to
   * be told what the form already knew.
   */
  const dialog = read("components/applications/domains/issue-cert-dialog.jsx");
  assert.match(dialog, /type === "custom" && !\(pem\.certificate\.trim\(\) && pem\.private_key\.trim\(\)\)/);
  assert.match(dialog, /disabled=\{submitting \|\| selected\?\.available === false \|\| Boolean\(issueReason\)\}/);
  assert.match(dialog, /<ReasonTooltip reason=\{submitting \? null : issueReason\}>/);

  for (const l of LOCALES) {
    const ssl = JSON.parse(read(`messages/${l}.json`)).applications.domains.ssl;
    assert.ok(ssl.uploadNeedsBoth, `${l} is missing ssl.uploadNeedsBoth`);
  }
});

test("a key that does not match its certificate is shown at the key", () => {
  /*
   * The backend attaches the mismatch to `private_key` specifically. It used to
   * arrive as a toast naming neither box — and two valid-looking PEM blocks
   * that simply are not a pair are indistinguishable from a transient failure
   * unless you are told which one is wrong.
   */
  const dialog = read("components/applications/domains/issue-cert-dialog.jsx");
  assert.match(dialog, /\["certificate", "private_key", "chain"\]/);
  assert.match(dialog, /aria-invalid=\{Boolean\(pemErrors\.private_key\)\}/);
  assert.match(dialog, /aria-invalid=\{Boolean\(pemErrors\.certificate\)\}/);
  assert.match(dialog, /setPemErrors\(fieldErrors\)/);

  // Cleared when a new attempt starts, or the old error outlives its cause.
  assert.match(dialog, /setRefusals\(\[\]\);\s*setPemErrors\(\{\}\);/);
});

test("a certificate that cannot renew itself offers Reissue before it lapses", () => {
  /*
   * Reissue rendered only when `expired`. The card already tells a
   * non-renewable certificate that it must be renewed by hand — keyed off the
   * same `renewable` flag — and then offered only Remove, so replacing one
   * meant deleting it first and dropping the site to plain http in between.
   * Waiting for expiry means the action that avoids an outage only appears
   * once the outage has begun.
   */
  const ssl = read("components/applications/domains/ssl-section.jsx");
  // `hasCoverageGap` joined the condition later — a renewing certificate with
  // a name missing also has something to do — but `!cert.renewable` is still
  // what this test is about.
  // Reissue is now shown in every state, so it is certainly there for these.
  assert.doesNotMatch(ssl, /DropdownMenu/);

  // The same flag the "renew this yourself" copy keys on — one source of truth
  // for whether this certificate looks after itself.
  assert.match(ssl, /cert\.renewable \? "ssl\.expiresRenew" : "ssl\.expiresManual"/);

  // Krishna chose (2026-09-23) to show Reissue on a renewing certificate too,
  // the same button as everywhere else, rather than behind a menu.
  const code = strip(ssl);
  assert.doesNotMatch(code, /\{true \? \(\s*<Button size="sm" onClick=\{\(\) => setIssueOpen\(true\)\}/);
});
