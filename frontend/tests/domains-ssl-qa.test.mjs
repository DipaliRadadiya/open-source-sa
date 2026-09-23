import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import path from "node:path";
import { addDomainFormSchema } from "../lib/schemas/domain.js";

const root = path.join(import.meta.dirname, "..");
const read = (p) => fs.readFileSync(path.join(root, p), "utf8");

const section = read("components/applications/domains/domains-section.jsx");
const ssl = read("components/applications/domains/ssl-section.jsx");

/** Code only. Prose that mentions an API is not a call to it. */
const stripComments = (src) => src.replace(/\/\*[\s\S]*?\*\//g, "").replace(/^\s*\/\/.*$/gm, "");

/** The first message Zod reports for a field, or null if it accepted the value. */
function fieldError(values, field) {
  const result = addDomainFormSchema.safeParse(values);
  if (result.success) return null;
  return result.error.issues.find((i) => i.path[0] === field)?.message ?? null;
}

test("a redirect target must be a full http(s) address", () => {
  /*
   * The backend rule is Laravel's `url`, which requires a scheme. This form
   * only checked the box was non-empty, so `example.com` — the most natural
   * thing to type into a field labelled "Redirect to" — was accepted here and
   * refused by the server with "The redirect to field must be a valid URL",
   * a field problem delivered as a toast that names nothing.
   */
  const base = { domain: "ok.example.com", type: "redirect", redirect_status: 301 };
  assert.equal(fieldError({ ...base, redirect_to: "example.com" }, "redirect_to"), "redirectTargetUrl");
  assert.equal(fieldError({ ...base, redirect_to: "banana" }, "redirect_to"), "redirectTargetUrl");
  // Parses as a URL, is not a destination. The value is written into the web
  // server's redirect directive, so "it parses" is not the bar.
  assert.equal(fieldError({ ...base, redirect_to: "javascript:alert(1)" }, "redirect_to"), "redirectTargetUrl");
  assert.equal(fieldError({ ...base, redirect_to: "data:text/html,x" }, "redirect_to"), "redirectTargetUrl");

  assert.equal(fieldError({ ...base, redirect_to: "https://ok.example.com" }, "redirect_to"), null);
  assert.equal(fieldError({ ...base, redirect_to: "http://ok.example.com/path" }, "redirect_to"), null);

  // Empty still reports the *missing* message, not the format one — they are
  // different problems and the second would read as nonsense on a blank box.
  assert.equal(fieldError({ ...base, redirect_to: "" }, "redirect_to"), "redirectTargetRequired");

  // An alias never carries a target, so the rule must not fire on one.
  assert.equal(
    fieldError({ domain: "ok.example.com", type: "alias", redirect_to: "", redirect_status: 301 }, "redirect_to"),
    null,
  );
});

test("a domain is bounded by the same 253 the server enforces", () => {
  const long = "a".repeat(250) + ".com"; // 254
  assert.equal(fieldError({ domain: long, type: "alias", redirect_status: 301 }, "domain"), "hostnameTooLong");
  assert.equal(fieldError({ domain: "ok.example.com", type: "alias", redirect_status: 301 }, "domain"), null);
});

test("removing a covered name warns that the certificate stops renewing", () => {
  /*
   * certbot validates every name in a certificate's lineage and fails the
   * WHOLE renewal if any one of them cannot be reached. So removing a covered
   * name quietly stops the certificate renewing for the names that are still
   * fine, and nothing goes wrong until it expires. The dialog said only "the
   * application will stop answering on this name".
   */
  assert.match(section, /deleteTarget && coverageOf\(deleteTarget\.domain\) === "covered"/);
  assert.match(section, /removeConfirm\.onCertificate/);
});

test("promoting a name the certificate does not cover warns first", () => {
  // The address visitors are sent to would answer on 443 with a certificate
  // issued for someone else — a browser refusal, not a downgrade.
  assert.match(section, /promoteTarget && coverageOf\(promoteTarget\.domain\) === "uncovered"/);
  assert.match(section, /promote\.notOnCertificate/);
});

test("one function answers coverage, and 'we cannot tell' is one of its answers", () => {
  /*
   * There were briefly two predicates here for the same question, reading
   * different fields with OPPOSITE defaults — `!missing_domains.includes(x)`
   * (unknown → covered) beside `domains.includes(x)` (unknown → not). Each
   * default was right for its own caller and neither said so, which is exactly
   * how two functions that agree today stop agreeing.
   *
   * Merged into one returning covered / uncovered / unknown, so the callers
   * spell out what they do with "unknown" instead of inheriting it.
   */
  assert.match(section, /function certificateCoverage\(certificate, domain\)/);
  assert.doesNotMatch(section, /function coveredByCertificate/, "the second predicate is gone");
  assert.doesNotMatch(section, /const onCertificate =/, "and so is the third");

  // A pending or failed certificate secures nothing, so its coverage is not a
  // fact about what visitors get.
  assert.match(section, /if \(certificate\?\.status !== "active"\) return "unknown";/);

  // The two callers want opposite things from "unknown", and both say so.
  // The link keeps https — guessing the other way downgrades a whole panel of
  // working links to plain http.
  assert.match(section, /coverageOf\(domain\.domain\) !== "uncovered" \? "https" : "http"/);
  // The dialogs stay quiet — guessing the other way puts a scary warning on
  // every confirm the moment a field goes missing.
  assert.match(section, /=== "covered"/);
  assert.match(section, /=== "uncovered"/);
});

test("Verify DNS is offered to anyone who can see the page", () => {
  /*
   * The route is gated by `app_domain` at VIEW level — re-checking DNS changes
   * nothing on the server. It was rendered inside `canManage`, so the one
   * control a read-only holder could legitimately use was the one they could
   * not see, while "is my DNS pointing here yet?" is their commonest question.
   */
  const verifyAt = section.indexOf('onClick={() => onVerify(domain)}');
  const manageAt = section.indexOf("{canManage ? (");
  assert.ok(verifyAt !== -1 && manageAt !== -1);
  assert.ok(verifyAt < manageAt, "Verify must sit outside the canManage branch");
});

test("both deletes treat 'already gone' as done", () => {
  for (const [name, src] of [["domain", section], ["certificate", ssl]]) {
    assert.match(src, /error\?\.response\?\.status === 404/, `${name} delete must recognise a 404`);
  }
  assert.match(section, /toast\.info\(t\("toast\.removedAlready"/);
  assert.match(ssl, /toast\.info\(t\("ssl\.removedAlready"\)\)/);
});

test("removing the certificate says so", () => {
  // It drops the application back to plain HTTP — the most consequential thing
  // this card can do, and it did it in silence.
  assert.match(ssl, /toast\.success\(t\("ssl\.removed"\)\)/);
});

test("the issuing poll gives up", () => {
  /*
   * No end condition: a certificate wedged in `issuing` polled every three
   * seconds for as long as the tab stayed open — 20 real API calls a minute
   * against a 180/min budget, for hours.
   */
  assert.match(ssl, /const POLL_LIMIT = \(10 \* 60 \* 1000\) \/ POLL_MS;/);
  assert.match(ssl, /if \(\+\+ticks > POLL_LIMIT\)/);
});

test("the failed card runs the API's sentence through apiMessage", () => {
  // Raw, an untranslated lookup key reached the card and read as the panel
  // being broken rather than the certificate.
  assert.match(ssl, /const certMessage = apiMessage\(/);
  assert.doesNotMatch(ssl, /\{cert\.message\}/, "the raw field must not be rendered");
});

test("the expiry line gives a date, not a second relative phrase", () => {
  /*
   * "Expires 1 month from now (59 days)" — the same fact twice, in two units
   * that disagree, because `expires_at_human` is the backend's rounding and
   * `days_remaining` is exact. The vague half is the one nobody can act on.
   */
  assert.match(ssl, /format\.dateTime\(when, \{ day: "numeric", month: "long", year: "numeric" \}\)/);
  assert.match(ssl, /when: expiresOn \?\? cert\.expires_at_human/, "fall back when there is no machine-readable date");

  /*
   * ⚠️ `parseApiDate`, never `new Date`.
   *
   * This API sends `20-11-2026 04:34:36` — day first, no timezone — which
   * `new Date` cannot parse. The first version of this fix used `new Date`,
   * was verified against a stub that happened to send ISO, and fell back to
   * `expires_at_human` on every real certificate. It did nothing on the panel
   * it was written for, and the stub is what hid it.
   */
  assert.match(ssl, /const when = parseApiDate\(value\);/);
  assert.doesNotMatch(stripComments(ssl), /new Date\(/, "no raw Date parsing of an API timestamp");
  // Never `toLocaleDateString` — dates follow the panel's locale like the rest.
  // Comments stripped first: the docblock above the fix NAMES the thing it
  // replaced, so matching the raw file finds my own prose and passes on a file
  // that still calls it.
  assert.doesNotMatch(stripComments(ssl), /toLocaleDateString|toLocaleString/);
});

test("an expired certificate that still forces HTTPS says the application is unreachable", () => {
  /*
   * The two facts were on the card separately — "HTTPS is not working" at the
   * top, and a Force HTTPS switch at the bottom still described as "Redirect
   * every visitor from HTTP to HTTPS" — and nothing joined them up. Together
   * they mean port 80 redirects to 443, 443 presents an expired certificate,
   * and the browser refuses the page: the application is entirely unreachable,
   * which is strictly worse than the same certificate with the redirect off.
   */
  assert.match(ssl, /\{expired && cert\.force_https \?/);
  assert.match(ssl, /ssl\.expiredForcedHttps/);
  // And a way out, not just a diagnosis — the one state where the right advice
  // is "turn something off".
  assert.match(ssl, /onClick=\{\(\) => onToggleForceHttps\(false\)\}/);
  assert.match(ssl, /ssl\.turnOffForceHttps/);
});

test("the issuing card offers something to press", () => {
  /*
   * A spinner and two lines, no control at all — so an issuance that wedges
   * left the reader with nothing to do on the screen that decides whether the
   * application serves HTTPS. Capping the poll made it worse: after ten
   * minutes the spinner is not even asking any more.
   */
  const issuing = ssl.slice(ssl.indexOf("if (isPending(cert))"), ssl.indexOf('if (cert.status === "failed")'));
  assert.match(issuing, /setDeleteOpen\(true\)/, "the stuck state needs a way back");
  assert.match(issuing, /ssl\.issuingStuck/);
});

test("every date on the card goes through one formatter", () => {
  /*
   * `expires_at` is an ISO timestamp and `served_expires_at` is a plain date,
   * so the stale line read "Being served: expires 2026-08-01 · On disk:
   * expires 2026-11-20T12:00:00+00:00" — a machine timestamp, timezone offset
   * and all, next to a human date in the same sentence.
   */
  assert.match(ssl, /const asDate = \(value\) =>/);
  assert.match(ssl, /served: asDate\(cert\.served_expires_at\)/);
  assert.match(ssl, /onDisk: asDate\(cert\.expires_at\) \?\? "—"/);
  // No raw date field may reach a message argument.
  assert.doesNotMatch(
    stripComments(ssl),
    /(served|onDisk|when): cert\.(expires_at|served_expires_at)\b(?!_human)/,
    "a raw date field must not be interpolated",
  );
});

/* ---------------------------------------------------------------------------
 * Layout and visual hierarchy
 * ------------------------------------------------------------------------ */

const page = read("app/(app)/applications/[application]/domains/page.jsx");

test("the tabs stay, and the certificate tab carries its own status", () => {
  /*
   * I removed these and should not have: the merge was listed as a research
   * question, not an approved change. They are deliberate — the docblock
   * argues the panel norm for a one-cert-per-site model, and the SSL tab's
   * icon answers "am I secured?" without opening it, which is what makes the
   * split cost nothing.
   */
  assert.match(page, /<DomainsSslTabs/);
  const tabs = read("components/applications/domains/domains-ssl-tabs.jsx");
  assert.match(tabs, /status === "unknown"/, "a failed read must not draw a crossed-out padlock");
  assert.match(tabs, /forceMount/, "an in-flight issue keeps polling across a tab switch");
});

test("a healthy certificate does not lead with a destructive button", () => {
  /*
   * A renewing Let's Encrypt certificate correctly hides Reissue, which left a
   * red `destructive` Remove as the ONLY control on the card — the single
   * affordance on a healthy panel was "destroy this", and it was the loudest
   * thing in a green box.
   */
  // The footer splits: something wrong → both buttons out in the open;
  // nothing wrong → a menu, so the card's only affordance is not "destroy
  // this" sitting under the cursor in a green panel.
  assert.match(ssl, /expired \|\| !cert\.renewable \|\| hasCoverageGap \? \(/);
  assert.match(ssl, /ssl\.moreActions/, "the healthy card hides them behind a menu");
});

test("only one button per card carries the fill", () => {
  // Two solid blue buttons compete, and the one that should win is the instant
  // fix inside the alert — Reissue takes a minute and can fail.
  assert.match(ssl, /variant=\{expired && cert\.force_https \? "outline" : "default"\}/);
});

test("the row action column holds its slots", () => {
  /*
   * The open-site link only renders on a verified non-redirect row. Dropped
   * entirely, every button after it shifted left, so "Verify DNS" sat at a
   * different x on each row.
   */
  const slots = section.match(/inline-flex size-8 shrink-0 items-center justify-center/g) ?? [];
  assert.equal(slots.length, 2, "both the open-site and the menu slot are reserved");
});

test("a rejected certificate upload reports at the fields, not in a toast", () => {
  /*
   * Driven against a 422 carrying per-field errors: both messages land under
   * their own textarea and no toast appears. "The private key does not match
   * the certificate" in a toast points at neither box and leaves you rereading
   * both.
   */
  const dialog = read("components/applications/domains/issue-cert-dialog.jsx");
  assert.match(dialog, /setPemErrors\(fieldErrors\)/);
  assert.match(dialog, /\["certificate", "private_key", "chain"\]/);
});

test("a per-domain refusal lists the names and offers the force only where it helps", () => {
  /*
   * Driven: a 422 with `errors.domain` lists both names in the dialog, and
   * "Set up anyway" appears. Forcing then closes the dialog and the card moves
   * to issuing.
   *
   * The force is offered for a REACHABILITY failure only — a dry run that got
   * as far as the CA is not a reachability problem and forcing past it would
   * fix nothing. Confirmed by driving both verdicts: the button appears for
   * the first and not the second.
   */
  const dialog = read("components/applications/domains/issue-cert-dialog.jsx");
  assert.match(dialog, /setRefusals\(domainErrors\)/);
  assert.match(dialog, /dryRun\?\.status === "failed" && dryRun\?\.stage === "reachability"/);
});

/* ---------------------------------------------------------------------------
 * Reported from the live panel: "why it shows covers", "structure is not
 * attractive", "whose renew date is this?"
 * ------------------------------------------------------------------------ */

test("one Reissue per card, never two", () => {
  /*
   * A certificate that cannot auto-renew AND has a missing name rendered TWO
   * Reissue buttons — one inside the missing-names panel, one in the footer,
   * the second of them blue. Seen on the live panel.
   *
   * I missed it because I tested "uploaded certificate" and "missing domains"
   * as separate fixtures and never combined them. The real data had both.
   */
  const panels = (ssl.match(/onClick=\{\(\) => setIssueOpen\(true\)\}/g) ?? []).length;
  assert.equal(panels, 3, "enable card, failed card, and ONE footer button");
  // The two per-warning copies are gone.
  assert.doesNotMatch(ssl, /ssl\.staleDomains/);
  assert.doesNotMatch(ssl, /ssl\.missingDomains/);
});

test("every name is in one list, each saying where it stands", () => {
  /*
   * Three sources answering one question — "which of my names does this
   * secure?" — were rendered in three places: a "Covers" list, a missing-names
   * panel and a stale-names panel. Merged into one list with per-row state.
   */
  assert.match(ssl, /const names = \[/);
  assert.match(ssl, /state: "covered"/);
  assert.match(ssl, /state: "missing"/);
  assert.match(ssl, /state: "stale"/);
  assert.match(ssl, /ssl\.nameMissing/);
  assert.match(ssl, /ssl\.nameStale/);

  // No heading above it. "Covers" was a label over a single line on the common
  // certificate, and a count read as wrong ("Secures 1 name" above two rows).
  assert.doesNotMatch(ssl, /coversLabel|coversNames/);
});

test("a coverage gap withdraws the green claim", () => {
  /*
   * Renewal is going to fail for every name on the certificate, so a green
   * tick beside "HTTPS is active" is a claim the panel cannot support.
   *
   * It used to be a tinted FRAME, which is a claim about the whole card and
   * put a coloured box round a coloured box. The tint is gone; the state now
   * lives in the one place that is about state — the status icon — and the
   * frame stays neutral in every case.
   */
  // `healthy` is the single predicate: only a certificate with nothing wrong
  // with it gets the green tick. Everything else gets the alert shield.
  assert.match(ssl, /const healthy = !expired && !servingStale && !hasCoverageGap;/);
  assert.match(ssl, /icon=\{healthy \? ShieldCheck : ShieldAlert\}/, "the tick is for healthy alone");

  /*
   * Tone reaches the icon chip and a 2px edge — never a fill.
   *
   * Every rejected version of this card made the same mistake at a different
   * scale: a red panel inside a red frame beside a red button. The one wash
   * any state is allowed is destructive's 2%, which is below the threshold at
   * which it reads as "a red box" and above the one at which it reads as
   * nothing at all.
   */
  const fills = [...ssl.matchAll(/tint: "([^"]*)"/g)].map((m) => m[1]).filter(Boolean);
  assert.deepEqual(fills, ["bg-destructive/[0.02]"], "only one state may wash its tile at all");
  assert.doesNotMatch(ssl, /bg-success\/5\b/, "and none may fill anything green");

  // `hasCoverageGap` must still be declared before anything reads it: `const`
  // is not hoisted, and when it was declared after, two of three states
  // crashed with "Cannot access before initialization" while the third
  // survived only because `expired ||` short-circuits past it. Build and lint
  // both passed.
  const declared = ssl.indexOf("const hasCoverageGap =");
  const firstUse = ssl.indexOf("const healthy =");
  assert.ok(declared !== -1 && firstUse !== -1);
  assert.ok(declared < firstUse, "hasCoverageGap must be declared before it is read");
});

test("a gap surfaces the way to fix it", () => {
  // A renewing certificate with a name missing has something to do, and the
  // warning tells you to reissue — so hiding the only Reissue in a menu is the
  // same mistake as showing two, from the other side.
  assert.match(ssl, /expired \|\| !cert\.renewable \|\| hasCoverageGap \? \(/);
});

