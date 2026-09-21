import test from "node:test";
import { createRequire } from "node:module";
const require = createRequire(import.meta.url);
import assert from "node:assert/strict";
import fs from "node:fs";

/*
 * Krishna: "we cannot define what is issue from error page… not showing api in
 * network tab so how user will identify that what is issue?"
 *
 * Both halves are true and the second is why the first matters. The session
 * and the permission catalog are fetched during SSR, so a failure there leaves
 * NO row in the browser's Network tab — the request happened on a machine the
 * reader cannot see. The error card is the only evidence that will ever exist,
 * and it was printing a digest.
 *
 * Driven against a stub API, reading innerText in a browser (a grep of the
 * HTML proves nothing — every error string is in the embedded message bundle):
 *
 *   500  -> "The server had a problem"     + GET /api/auth/me  500
 *   503  -> "The panel is updating"        (its own screen, unchanged)
 *   403  -> "You do not have access"       + GET /api/auth/me  403
 *   401  -> the login form                 (unchanged)
 *   down -> "Could not reach the server"   + GET /api/auth/me  no reply
 */

const read = (p) => fs.readFileSync(p, "utf8");
const strip = (s) => s.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");
const LOCALES = read("i18n/routing.js")
  .match(/export const locales = \[([^\]]+)\]/)[1]
  .split(",")
  .map((c) => c.trim().replace(/['"]/g, ""))
  .filter(Boolean);
const messages = Object.fromEntries(
  LOCALES.map((l) => [l, JSON.parse(read(`messages/${l}.json`))]),
);

test("a request that never completed is its own kind, not a status of 0", () => {
  /*
   * A refused connection, a dead DNS name and a TLS failure produce no
   * response at all. Left to the boundary they became a digest — and they are
   * the failures the reader can most easily fix.
   */
  const src = read("lib/api/request-failed.js");
  assert.match(src, /if \(this\.status === null\) return "network"/);
  for (const fetcher of ["lib/auth/get-current-user.js", "lib/permissions/get-permissions.js"]) {
    assert.match(
      read(fetcher),
      /catch \(cause\) \{\s*throw new RequestFailedError\(\{ url, status: null, cause \}\);/,
      fetcher,
    );
  }
});

test("the kinds are the ones the rest of the panel already uses", () => {
  // Same vocabulary as `read()`/`LoadFailed`, so a failure reads the same
  // whether it took out one card or the whole screen.
  const src = read("lib/api/request-failed.js");
  for (const kind of ["network", "forbidden", "notFound", "server"]) {
    assert.match(src, new RegExp(`return "${kind}"`), kind);
    for (const locale of LOCALES) {
      const reason = messages[locale].errors.reason[kind];
      assert.ok(reason?.title && reason?.body, `${locale} errors.reason.${kind}`);
    }
  }
});

test("every status group gets the cause that is true of IT", () => {
  /*
   * Krishna: "and what message now it will show for other status codes?"
   *
   * It answered "server" for everything that was not 403 or 404 — so a 400 or
   * a 405 was reported as "something failed inside the API", which is the
   * opposite of what a 4xx means and sends the reader to the wrong log.
   */
  const { RequestFailedError } = require("../lib/api/request-failed.js");
  const kindOf = (status) => new RequestFailedError({ url: "https://h/api/auth/me", status }).kind;

  assert.equal(kindOf(null), "network");
  assert.equal(kindOf(400), "rejected", "the server refused what we sent — it did not fail");
  assert.equal(kindOf(405), "rejected");
  assert.equal(kindOf(422), "rejected");
  assert.equal(kindOf(403), "forbidden");
  assert.equal(kindOf(404), "notFound");
  assert.equal(kindOf(500), "server");
  assert.equal(kindOf(502), "gateway", "the API never ran — a different fix from a 500");
  assert.equal(kindOf(504), "gateway");
  assert.equal(kindOf(507), "server");

  /*
   * A 3xx has no branch on purpose. `fetch` follows redirects, so the
   * http->https case this would have described either resolves or arrives as a
   * transport error. Driven against a stub returning 301: the page showed
   * "Your server is not answering", not a status. A kind nothing can reach is
   * three strings in eight languages that nobody will ever read.
   */
  assert.equal(kindOf(301), "rejected", "unreachable in practice, but never undefined");
});

test("no status can reach the card without an explanation behind it", () => {
  // A kind with no copy renders the raw key. Every branch of `kind` must have
  // title/body/next in all eight locales.
  const src = read("lib/api/request-failed.js");
  const kinds = [...src.matchAll(/return "([a-zA-Z]+)";/g)].map((m) => m[1]);
  assert.ok(kinds.length >= 5, `expected every branch, found ${kinds.join()}`);
  for (const kind of new Set(kinds)) {
    for (const locale of LOCALES) {
      const ns = messages[locale].errors.why?.[kind];
      assert.ok(ns, `${locale} has no errors.why.${kind}`);
      for (const key of ["title", "body", "next"]) {
        assert.ok(ns[key]?.trim(), `${locale} errors.why.${kind}.${key}`);
      }
    }
  }
});

test("the card leads with why, in a sentence, for every kind", () => {
  /*
   * Krishna: "not like this showing api and all. i want to see proper error
   * message why this error is getting."
   *
   * The first version led with the request line. That answers "what happened
   * to the request" — a developer's question. Each kind now gets a real
   * explanation and a real next step instead of a shared "try again".
   */
  const src = read("components/sections/request-failed.jsx");
  assert.match(src, /t\(`why\.\$\{kind\}\.title`\)/);
  assert.match(src, /t\(`why\.\$\{kind\}\.body`, values\)/);
  assert.match(src, /t\(`why\.\$\{kind\}\.next`, values\)/);
  for (const locale of LOCALES) {
    for (const kind of ["network", "server", "forbidden", "notFound"]) {
      const ns = messages[locale].errors.why?.[kind];
      assert.ok(ns, `${locale} errors.why.${kind}`);
      for (const key of ["title", "body", "next"]) {
        assert.ok(ns[key]?.trim(), `${locale} errors.why.${kind}.${key}`);
      }
    }
  }
});

test("the request line is folded away, not removed", () => {
  /*
   * Still the only record that will ever exist — the fetch happens during SSR,
   * so there is no Network tab row — but it is a support artefact, not the
   * answer to "why". Native <details>, because a screen that has already lost
   * one thing should not need hydration to open.
   */
  const src = read("components/sections/request-failed.jsx");
  assert.match(src, /<details/);
  assert.doesNotMatch(src, /useState/);
  assert.match(src, /status === null \? t\("request\.noResponse"\) : status/);
  for (const locale of LOCALES) {
    const ns = messages[locale].errors.request;
    for (const key of ["endpoint", "status", "host", "noResponse", "details"]) {
      assert.equal(typeof ns?.[key], "string", `${locale} errors.request.${key}`);
    }
  }
});

test("the server's own message is shown, and leads the footer", () => {
  /*
   * Krishna: "why we cannot see actual message instead of showing just Your
   * server returned an error… should we show why this is happening?"
   *
   * Yes. The API's `message` is the reason; our sentence is only the category.
   * And this backend sanitises it on purpose — bootstrap/app.php rewrites 404
   * and 405 into translated strings so they cannot leak a model class or the
   * route map. Withholding that was the panel overruling its own server.
   */
  const card = read("components/sections/request-failed.jsx");
  assert.match(card, /\{serverMessage \? \(/);
  assert.match(card, /t\("request\.serverSaid"\)/);
  assert.match(card, /<blockquote/, "attributed, so it is not mistaken for the panel talking");
  // Above "What to do": the server's reason outranks our category.
  assert.ok(
    card.indexOf("request.serverSaid") < card.indexOf('t("whatToDo")'),
    "the server's own words must come first",
  );
  for (const locale of LOCALES) {
    assert.ok(messages[locale].errors.request.serverSaid?.trim(), locale);
  }
});

test("a stack trace is never carried, and its presence becomes a warning", () => {
  /*
   * `trace`/`file`/`line`/`exception` appear only with APP_DEBUG=true. They
   * are not read — but the fact that they were THERE is worth saying, because
   * this card renders on a login page anyone can reach.
   */
  const body = read("lib/api/error-body.js");
  assert.match(body, /\["trace", "exception", "file", "line"\]\.some/);
  assert.doesNotMatch(strip(body), /data\.trace|data\.file|data\.line/);

  const card = read("components/sections/request-failed.jsx");
  assert.match(card, /\{debug \? \(/);
  assert.match(card, /t\("request\.debugWarning"\)/);
  for (const locale of LOCALES) {
    assert.ok(messages[locale].errors.request.debugWarning?.trim(), locale);
  }
});

test("the message is bounded and only read from JSON", () => {
  // A debug-mode message can be an entire SQL statement, and an HTML error
  // page from nginx is a wall of markup whose useful part is the status.
  const body = read("lib/api/error-body.js");
  assert.match(body, /const MAX = 300/);
  assert.match(body, /if \(!type\.includes\("json"\)\) return empty/);
  assert.match(body, /message\.length > MAX/);
  // A validation body's own `message` is generic; the first real complaint is
  // the one worth showing.
  assert.match(body, /data\.errors/);
});

test("the error is converted to plain props before it crosses to the client", () => {
  // An Error does not serialise across the server/client boundary as itself.
  const src = read("lib/api/request-failed.js");
  assert.match(src, /export function requestFailureProps\(error\)/);
  for (const caller of [
    "app/(auth)/login/page.jsx",
    "app/(auth)/register/page.jsx",
    "app/(app)/layout.jsx",
    "app/admin/layout.jsx",
  ]) {
    assert.match(read(caller), /\{\.\.\.requestFailureProps\(error\)\}/, caller);
  }
});

test("every guard that answers a rate limit answers a failed request too", () => {
  // The two shells each catch in more than one place; a new catch block that
  // forgets this one puts the digest back.
  for (const layout of ["app/(app)/layout.jsx", "app/admin/layout.jsx"]) {
    const src = read(layout);
    const rate = (src.match(/isRateLimited\(error\)/g) ?? []).length;
    const failed = (src.match(/isRequestFailed\(error\)/g) ?? []).length;
    assert.equal(failed, rate, layout);
  }
});

test("the copy never invents a service name", () => {
  /*
   * An earlier draft told the reader to run `systemctl status sv-oss-api`.
   * There is no such unit — the API is served by Apache and PHP-FPM — so the
   * one instruction on the screen would have been wrong. The copy names the
   * web server and the API's own log, both of which exist on every install.
   */
  for (const locale of LOCALES) {
    for (const kind of ["network", "server", "forbidden", "notFound"]) {
      assert.doesNotMatch(
        messages[locale].errors.why[kind].next,
        /systemctl|sv-oss-api|nginx -t/,
        `${locale} errors.why.${kind}.next`,
      );
    }
  }
});
