import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Krishna: "why getting this error?" — the generic "Something went wrong" card
 * with a digest, on a panel's LOGIN page.
 *
 * Nothing was wrong with the frontend. That panel's API was answering 503 with
 * `Retry-After: 60` — Laravel maintenance mode, which is step two of a panel
 * update (`artisan down --retry=60` in UpdateScript.php). The update had died
 * before `artisan up`, so the API had been refusing every request since.
 *
 * The panel knew that and threw it away: `getMe` maps 401/419 to "signed out"
 * and 429 to its own type, and everything else to a bare Error. In a
 * production build the boundary receives only a digest, so by the time it
 * renders, "the panel is updating" and "the panel crashed" are the same event.
 *
 * Driven against a stub API on all three answers:
 *   503 -> the maintenance screen, naming `php artisan up`
 *   401 -> the login form, unchanged
 *   500 -> the generic error card, unchanged
 */

const read = (p) => fs.readFileSync(p, "utf8");
// Comments describe the decision; asserting against them passes on prose and
// fails on a rename. Both of this file's layout assertions did exactly that.
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);
const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]),
);

test("a 503 gets its own identity, at both fetches the app cannot render without", () => {
  const auth = read("lib/auth/get-current-user.js");
  const perms = read("lib/permissions/get-permissions.js");
  assert.match(auth, /if \(res\.status === 503\) throw new PanelUnavailableError\("auth\/me"\)/);
  assert.match(perms, /if \(res\.status === 503\) throw new PanelUnavailableError\("permissions"\)/);
});

test("it is recognised by name, not instanceof", () => {
  // The class can be evaluated more than once across bundle chunks; the name
  // is what survives that. Same reasoning as RateLimitedError.
  const src = read("lib/api/unavailable.js");
  assert.match(src, /error\?\.name === "PanelUnavailableError"/);
});

test("the login page answers it itself, because the boundary cannot", () => {
  /*
   * This is the whole point. `app/(auth)/error.jsx` gets `{digest}` and
   * nothing else in production, so catching it there is impossible — the
   * page that made the request is the last place that still knows.
   */
  for (const page of ["app/(auth)/login/page.jsx", "app/(auth)/register/page.jsx"]) {
    const src = read(page);
    assert.match(src, /if \(isPanelUnavailable\(error\)\) return <PanelUnavailableCard \/>;/, page);
    assert.match(src, /throw error;/, `${page} must rethrow anything else`);
  }
});

test("both shells answer it too, beside the rate limit they already answer", () => {
  for (const layout of ["app/(app)/layout.jsx", "app/admin/layout.jsx"]) {
    const src = read(layout);
    const rate = (src.match(/isRateLimited\(error\)/g) ?? []).length;
    const down = (src.match(/isPanelUnavailable\(error\)/g) ?? []).length;
    assert.equal(down, rate, `${layout}: every rate-limit guard needs the 503 guard beside it`);
  }
});

test("the copy names the stuck-update case, in every locale", () => {
  /*
   * "Try again" alone is wrong here. A rate limit always clears; this does not
   * — an update that stopped after `artisan down` leaves the server here until
   * somebody runs `artisan up`. The reader who waited and came back is the one
   * who needs that sentence.
   */
  for (const locale of LOCALES) {
    const ns = messages[locale].errors?.unavailable;
    assert.ok(ns, `${locale} is missing errors.unavailable`);
    for (const key of ["title", "body", "stuck"]) {
      assert.equal(typeof ns[key], "string", `${locale} errors.unavailable.${key}`);
      assert.ok(ns[key].trim().length > 0, `${locale} errors.unavailable.${key} is empty`);
    }
  }
  // The command is rendered as code by the component, never translated.
  const card = read("components/sections/panel-unavailable.jsx");
  assert.match(card, /php artisan up/);
  for (const locale of LOCALES) {
    assert.doesNotMatch(
      messages[locale].errors.unavailable.stuck,
      /artisan/,
      `${locale}: the command belongs to the component, not the sentence`,
    );
  }
});

test("the card renders without a viewport of its own, and the full screen wraps it", () => {
  // The auth layout already centres its child in a max-w-sm column; a second
  // min-h-svh inside that squeezes the box into a narrow strip.
  const src = strip(read("components/sections/panel-unavailable.jsx"));
  const card = src.slice(
    src.indexOf("export function PanelUnavailableCard"),
    src.indexOf("export function PanelUnavailable("),
  );
  assert.doesNotMatch(card, /min-h-svh/);
  assert.match(src, /export function PanelUnavailable\(\)[\s\S]*min-h-svh[\s\S]*<PanelUnavailableCard \/>/);
});

test("it is not styled as a failure", () => {
  /*
   * Nothing is broken and nothing was lost — the server is doing exactly what
   * it was told. Same restraint as the rate-limit screen.
   *
   * Asserted through the shared shell now: the card asks for the muted tone,
   * and the shell is what turns that into a surface. Pinning the class here
   * only proved where the string lived.
   */
  const card = strip(read("components/sections/panel-unavailable.jsx"));
  assert.match(card, /tone="muted"/);
  assert.doesNotMatch(card, /destructive/);

  const shell = strip(read("components/sections/failure-screen.jsx"));
  assert.match(shell, /muted \? "border-border" : "border-destructive\/25"/);
});

test("the card is a solid surface, not a wash over the page", () => {
  /*
   * Krishna: "this card ui looks merged with background."
   *
   * It filled itself with `bg-muted/30` and sat on the auth layout's tinted
   * gradient, so at 30% opacity there was no edge between them. The login card
   * on the same screen uses `bg-card` with `shadow-xl shadow-black/5`; two
   * cards on one page should not disagree about what a card is.
   */
  const shell = strip(read("components/sections/failure-screen.jsx"));
  assert.match(shell, /bg-card/);
  assert.match(shell, /shadow-xl shadow-black\/5/);
  assert.doesNotMatch(shell, /bg-muted\/30|bg-destructive\/5/, "a translucent fill is what merged it");

  // The same treatment the login card uses, quoted from it so the two cannot
  // drift apart silently.
  const login = read("app/(auth)/login/page.jsx");
  assert.match(login, /shadow-xl shadow-black\/5/);
});

test("the three blocks are separated by more space than the lines inside them", () => {
  /*
   * Krishna: "need to improve this ui. everything looks merged."
   *
   * The cause was uniform spacing — icon, heading, sentence, button and a
   * troubleshooting note in one gap-4 column. The gaps BETWEEN groups have to
   * beat the gaps inside them, and the last group needs a rule and a label or
   * it reads as a fourth sentence.
   */
  const shell = strip(read("components/sections/failure-screen.jsx"));
  assert.doesNotMatch(shell, /gap-4/, "a single uniform gap is what merged it");
  assert.match(shell, /<p className="mt-2 /, "the sentence sits close to its heading");
  assert.match(shell, /\{action\}/);
  assert.match(shell, /mt-7 w-full border-t pt-5 text-left/, "the footer is ruled off and left-aligned");
  assert.match(shell, /export function FailureFooterLabel/);
});

test("the command is its own row, not a word inside a sentence", () => {
  // Somebody is about to paste it into an SSH session.
  const card = read("components/sections/panel-unavailable.jsx");
  assert.match(card, /const COMMAND = "php artisan up"/);
  assert.match(card, /<code[^>]*>\{COMMAND\}<\/code>/);
  assert.match(card, /<CopyButton value=\{COMMAND\}/);
});
