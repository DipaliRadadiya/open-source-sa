import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync, existsSync } from "node:fs";

/**
 * The redirect flow's frontend half, pinned where it can silently regress.
 *
 * All three of these are structural facts a refactor can undo without any
 * visible symptom in development — and each one only fails in front of a real
 * Google account, which is the worst place to find out.
 */

const CALLBACK_PAGE = "app/(app)/integrations/storage/oauth/callback/page.jsx";
const CALLBACK_COMPONENT = "components/integrations/storage/google-drive-callback.jsx";
const CONNECT = "components/integrations/storage/google-drive-connect.jsx";
const API = "lib/api/storage.js";

const read = (path) => readFileSync(new URL(`../${path}`, import.meta.url), "utf8");

/*
 * This exact path is registered in somebody's Google Cloud Console. Moving the
 * file renames the URL, and every panel that registered the old one breaks at
 * the last step of the flow — after consent, with a mismatch error. Google is
 * the only place the old value still exists, so nothing in this repo would
 * point at the cause.
 */
test("the callback page stays on the path registered with Google", () => {
  assert.ok(
    existsSync(new URL(`../${CALLBACK_PAGE}`, import.meta.url)),
    `${CALLBACK_PAGE} is the redirect URI operators paste into Google — it cannot move`,
  );

  const backend = readFileSync(
    new URL("../../backend/app/Services/Server/Backups/Storage/GoogleOauthRedirect.php", import.meta.url),
    "utf8",
  );

  // The backend builds the URI it sends Google; the page answers it. Two
  // copies of one string is exactly the pair that drifts.
  assert.match(
    backend,
    /CALLBACK_PATH = '\/integrations\/storage\/oauth\/callback'/,
    "the backend's redirect path no longer matches the page that serves it",
  );
});

/*
 * The authorization code is single-use and the sealed state is burned on first
 * use, so a second exchange fails by design. React's strict mode runs effects
 * twice in development, which would turn a working connection into a rendered
 * failure — the guard is what stops it.
 */
test("the callback exchanges the code exactly once", () => {
  const source = read(CALLBACK_COMPONENT);

  assert.match(source, /started\s*=\s*useRef\(false\)/, "no re-entry guard on the exchange");
  assert.match(
    source,
    /started\.current\s*=\s*true/,
    "the guard is never set, so strict mode would exchange the code twice",
  );
});

/*
 * The client secret must never exist in the browser, and the destination id
 * must never be accepted from it. Both are properties of what this file is
 * allowed to send.
 */
test("the browser sends only the code and the state", () => {
  const source = read(API);

  assert.doesNotMatch(source, /client_secret/, "the client secret must never reach the browser");
  assert.match(source, /post\(\s*"\/integrations\/storage\/oauth\/callback",\s*\{\s*code,\s*state\s*\}/,
    "the callback call must carry code and state and nothing else");
});

/*
 * The device flow is gone from the UI: nothing polls, and nothing displays a
 * code for someone to type on another device.
 */
test("nothing polls for approval any more", () => {
  const source = read(CONNECT);

  assert.doesNotMatch(source, /setInterval|pollDriveConnect/, "the connect button still polls");
  assert.match(source, /window\.location\.assign/, "Connect no longer navigates to Google");
});

/*
 * Google compares the redirect URI byte for byte, so the operator must be able
 * to copy it rather than retype it. A hand-typed URI with a trailing slash
 * fails after consent — the most expensive place in the flow to find a typo.
 */
test("the redirect URI is shown to be copied, not described", () => {
  const source = read(CONNECT);

  assert.match(source, /CopyButton/, "the redirect URI has no copy control");
  assert.match(source, /redirect_uri/, "the redirect URI from the API is never displayed");
});
