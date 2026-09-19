import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Krishna: "connecting git account popup needs to improve ui. currently it
 * looks conjuncted and unstructured. not properly scannable."
 *
 * Measured before touching it: the Access token field carried FOUR stacked
 * `FormDescription` blocks — the backend's prose about the scopes, a
 * "Create a token" link, a provider caveat, and "we only read, never push" —
 * plus a fifth grey box at the bottom of the Bitbucket form. All the same
 * size and colour, so there was no reading order: the one instruction that
 * mattered sat third of four.
 *
 * Two of the five said the same thing. The backend's `token_help` for
 * Bitbucket ends "A repository-scoped token will only list that repository",
 * and our bottom box opened "A token scoped to one repository will connect and
 * then show only that repository".
 *
 * After: one hint line per FIELD, scopes as code, the link on the label row.
 * Bitbucket 5 blocks → 2 (one per field), 643px → 502px tall.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const form = read("components/integrations/git/connect-form.jsx");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
const code = strip(form);
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);
const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]),
);

test("the credential is the last field, after what describes where it came from", () => {
  /*
   * Not cosmetic. `createTokenUrl(provider, host)` builds the GitLab link FROM
   * the self-hosted URL, and GitLab's field list arrives token-first — so a
   * self-hosted user clicked "Create a token on GitLab" and landed on
   * gitlab.com, the wrong server, because the field it depends on was below
   * it. Driven in a browser: with the host filled the href becomes
   * https://git.example.com/-/user_settings/personal_access_tokens.
   */
  assert.match(code, /function credentialLast\(fields\)/);
  assert.match(code, /credentialLast\(provider\.fields\)\.map/);
  // Sorted, not hardcoded to GitLab: a fourth provider with a host gets it too.
  assert.match(code, /Number\(isSecret\(a\)\) - Number\(isSecret\(b\)\)/);
});

test("the create-token link is an action on the label row, not a paragraph", () => {
  // It was the third of four identical grey blocks under the input — the one
  // thing on the field you can click, dressed as the prose around it.
  assert.match(code, /justify-between[^"]*">\s*<FormLabel/);
  assert.match(code, /isSecret\(spec\) && tokenUrl \?/);
  // And it is gone from the stack below.
  assert.doesNotMatch(code, /url=\{tokenUrl\}/);
});

test("each provider explains itself in exactly one line", () => {
  assert.match(code, /function ScopeHint\(/);
  for (const provider of ["github", "gitlab", "bitbucket"]) {
    for (const locale of LOCALES) {
      const value = messages[locale].git?.connect?.[`scopeHint_${provider}`];
      assert.equal(typeof value, "string", `${locale} scopeHint_${provider}`);
      assert.match(value, /<scope>/, `${locale} ${provider}: the scope should be code`);
    }
  }
});

test("the three blocks it replaced are gone from every locale", () => {
  // Deleted, not orphaned — a key left behind is a string nobody renders and
  // everybody keeps translating.
  for (const locale of LOCALES) {
    const ns = messages[locale].git.connect;
    for (const key of ["bitbucketScope", "bitbucketToken", "gitlabLegacy", "back"]) {
      assert.equal(ns[key], undefined, `${locale} still has ${key}`);
    }
  }
  assert.doesNotMatch(code, /bitbucketScope|bitbucketToken|gitlabLegacy/);
});

test("'we only read, never push' describes the panel, so it is the subtitle", () => {
  /*
   * It sat under the token input as if it were help for that field. It is a
   * statement about what the panel does with the credential — which is the
   * objection worth answering, just not four paragraphs deep.
   */
  assert.match(code, /description=\{t\("subtitle", \{ brand \}\)\}/);
  for (const locale of LOCALES) {
    assert.match(
      messages[locale].git.connect.subtitle,
      /\{brand\}/,
      `${locale} subtitle should name the panel`,
    );
  }
});

test("a provider we have no copy for still explains itself", () => {
  // The form renders whatever the backend describes, so a fourth provider must
  // not arrive with a blank field. It falls back to the API's own sentence.
  assert.match(code, /if \(!t\.has\(key\)\) \{/);
  assert.match(code, /fallback \? <FormDescription>\{fallback\}<\/FormDescription> : null/);
  assert.match(code, /fallback=\{spec\.help \?\? provider\.token_help\}/);
});

test("the form has a focal point, and it is the account being connected", () => {
  /*
   * "still it not looks modern and attractive" — the structural pass had
   * fixed the information and left three label-and-input pairs on a white
   * sheet with no anchor. The band is the same device the dashboard uses for
   * the server's identity: one tinted, elevated tile for the thing the screen
   * is about.
   */
  assert.match(code, /border-primary\/25 bg-primary\/\[0\.06\]/);
  assert.match(code, /<ProviderLogo provider=\{provider\.name\} className="size-6" \/>/);
});

test("the header chip is the task, because the band already carries the brand", () => {
  // The same logo twice in a 512px dialog reads as a rendering mistake.
  assert.match(code, /const HeaderIcon = KeyRound/);
});

test("one action in the footer, and going back sits beside what it changes", () => {
  /*
   * "Back" in the footer did exactly what "Change" in the band does, so the
   * dialog offered the same escape twice and gave the weaker of the two equal
   * footing with the only button anyone came to press.
   */
  assert.doesNotMatch(code, /t\("back"\)/);
  assert.match(code, /onClick=\{onBack\}[\s\S]{0,80}t\("change"\)/);
  for (const locale of LOCALES) {
    assert.equal(typeof messages[locale].git.connect.change, "string", `${locale} change`);
  }
});

test("the pasted-a-URL warning survives", () => {
  // The one block under the field that is allowed to compete with the hint,
  // because it only appears once the value already looks wrong.
  assert.match(code, /LOOKS_LIKE_URL\.test/);
  assert.match(code, /t\("looksLikeUrl"\)/);
});
