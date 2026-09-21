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
const REDIRECT_URI = "components/integrations/storage/google-drive-redirect-uri.jsx";
const CONNECT_DIALOG = "components/integrations/storage/connect-dialog.jsx";
const GET_STORAGE = "lib/storage/get-storage.js";
const SETUP = "components/integrations/storage/google-drive-setup.jsx";

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
 * to copy it rather than retype it. A hand-typed URL with a trailing slash
 * fails after consent — the most expensive place in the flow to find a typo.
 */
test("the redirect URI is shown to be copied, not described", () => {
  const source = read(REDIRECT_URI);

  assert.match(source, /CopyButton/, "the redirect URI has no copy control");
  assert.match(source, /break-all/, "a wrapped URL is required; truncation hides characters");
});

/*
 * The ordering property, and the one that actually matters to a first-time
 * user: this URL goes into the Google OAuth client at *creation* time, so it
 * has to be readable before there is a client ID to paste into the panel and
 * before any destination exists.
 *
 * An earlier version displayed it only after pressing Connect, from the start
 * response — which was both too late and literally dead code, since the browser
 * navigates to Google on the same line. Hence the assertions that the create
 * dialog shows it and that the connect button no longer tries to.
 */
test("the callback URL is available before any destination exists", () => {
  const createDialog = read(CONNECT_DIALOG);

  // Reached through the setup guide now, which renders it inside the step that
  // pastes it into Google. The property is unchanged: a reader who has created
  // nothing can still read the URL their OAuth client needs.
  assert.match(
    createDialog,
    /GoogleDriveSetup/,
    "the create form does not show the setup guide, so the callback URL is unreachable before setup",
  );
  assert.match(read(SETUP), /GoogleDriveRedirectUri/);

  // Panel-wide on the list response, not hung off a destination id.
  assert.match(read(GET_STORAGE), /google_oauth_redirect_uri/,
    "the redirect URI is not loaded with the destinations list");

  assert.doesNotMatch(read(CONNECT), /redirect_uri/,
    "the connect button still tries to render the URI it navigates away from");
});

/*
 * The setup guide exists because every failure this feature had in real use was
 * a Console step nobody had been told about — and each one surfaces much later,
 * as an error that says nothing about the step that caused it.
 *
 * These pin the two that cost real time. Both are invisible until they bite:
 * the API left disabled means sign-in works and every upload 403s, and an app
 * left in "Testing" runs for a week and then stops with nothing changed.
 */
test("the setup guide covers enabling the Drive API and publishing the app", () => {
  const en = JSON.parse(readFileSync(new URL("../messages/en.json", import.meta.url), "utf8"));
  const setup = en.storage.oauth.setup;

  assert.match(setup.step2.body, /most common mistake/i, "the disabled-API trap is not called out");
  assert.match(setup.step3.publish, /seven days/i, "the Testing-expiry trap is not called out");
  assert.match(setup.step3.publish, /publish/i);

  // Web application, not the device-flow client type the old guide named.
  assert.match(setup.step4.body, /Web application/);
  assert.doesNotMatch(JSON.stringify(setup), /Limited Input/i);
});

// A link to Cloud Console's front door lands a first-time reader on a dashboard
// of forty products. Each step points at the page it is about.
test("each setup step links to its own Console page", () => {
  const source = read(SETUP);

  for (const url of [
    "console.cloud.google.com/projectcreate",
    "console.cloud.google.com/apis/library/drive.googleapis.com",
    "console.cloud.google.com/apis/credentials/consent",
    "console.cloud.google.com/apis/credentials",
  ]) {
    assert.ok(source.includes(url), `missing deep link: ${url}`);
  }
});

// The redirect URI belongs inside the step that pastes it into Google, not
// floating somewhere else in the form.
test("the redirect URI sits in the step that registers it", () => {
  const source = read(SETUP);
  const step4 = source.slice(source.indexOf('n={4}'), source.indexOf('n={5}'));

  assert.match(step4, /GoogleDriveRedirectUri/);
});
