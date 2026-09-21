import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Krishna, on the Google Drive OAuth return page: "improve this ui properly".
 *
 * It was a normal panel page — a `PageHeader` reading "Connecting Google
 * Drive / Finishing the approval you just gave Google" above a small green box
 * announcing "Connected to Google Drive", with the rest of the viewport empty.
 *
 * So the page said two different things at once. The header was written for
 * the WORKING state and never changed, which meant on success it contradicted
 * the card below it, and on failure "Finishing the approval you just gave
 * Google" was simply untrue.
 *
 * It is now a centred card that owns its own heading — the same treatment the
 * 404 gets, and for the same reason: one job, one outcome, one way out.
 *
 * No new strings were needed. Every line already existed; they were in the
 * wrong places.
 */

const read = (p) => fs.readFileSync(p, "utf8");
const card = read("components/integrations/storage/google-drive-callback.jsx");
const page = read("app/(app)/integrations/storage/oauth/callback/page.jsx");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

test("the heading belongs to the outcome, not to the page", () => {
  const code = strip(card);
  // One title per state, chosen with the state.
  for (const status of ["working", "connected", "failed"]) {
    assert.match(code, new RegExp(`${status}: \\{`), `no view for ${status}`);
  }
  assert.match(code, /title: t\("callbackTitle"\)/);
  assert.match(code, /title: t\("connected"\)/);
  assert.match(code, /title: t\("callbackFailed"\)/);
  // And the page no longer states one of its own over the top.
  assert.doesNotMatch(strip(page), /PageHeader/);
});

test("the card is renderable in all three states", () => {
  /*
   * `connected` needs a live single-use Google authorization code, so it is
   * unreachable in any harness while the exchange lives inside the component.
   * Splitting the view out is what made the success state — the one this page
   * exists for — possible to look at at all.
   */
  assert.match(card, /export function CallbackCard\(\{ status, message \}\)/);
  assert.match(card, /return <CallbackCard status=\{status\} message=\{message\} \/>/);
});

test("there is no way out while the exchange is still in flight", () => {
  // Nothing to decide yet, and a button would invite leaving mid-request —
  // the code is single-use, so a re-entry cannot succeed.
  assert.match(strip(card), /status !== "working" \? \(/);
});

test("leaving is a link, not a click handler", () => {
  // Middle-click and open-in-new-tab both work, and it is the browser's own
  // navigation rather than a router push. Dipali fixed this once already; the
  // rewrite must not quietly undo it.
  assert.match(strip(card), /<a href=\{STORAGE_PAGE\}>/);
  assert.doesNotMatch(strip(card), /router\.push\(STORAGE_PAGE\)/);
});

test("the centred layout is registered with the PageHeader guard", () => {
  /*
   * The guard fails on a new hand-rolled <h1> AND on a stale allowance, so
   * this cannot be left behind if the page ever goes back to a normal shell.
   */
  const guard = read("scripts/check-page-header.mjs");
  assert.match(guard, /google-drive-callback\.jsx/);
  assert.match(guard, /centred OAuth result/);
});

test("no new strings were invented for the rearrangement", () => {
  // Every line already existed in `storage.oauth`; they were in the wrong
  // places. `callbackSubtitle` is the one casualty — it described the working
  // state from a header that outlived it.
  const locales = read("i18n/routing.js")
    .match(/export const locales = \[([^\]]+)\]/)[1]
    .split(",")
    .map((c) => c.trim().replace(/['"]/g, ""))
    .filter(Boolean);

  for (const locale of locales) {
    const ns = JSON.parse(read(`messages/${locale}.json`)).storage.oauth;
    for (const key of ["callbackTitle", "connected", "callbackFailed", "scopeNote", "finishing"]) {
      assert.equal(typeof ns[key], "string", `${locale} ${key}`);
    }
  }
});
