import test from "node:test";
import assert from "node:assert/strict";
import fs from "node:fs";
import { issueItems, localKeysSupersededBy } from "../lib/applications/issue-items.js";
import { applicationIssuesResponseSchema } from "../lib/schemas/application.js";

// The six AppIssueDetector emits, in the shape it emits them.
const CERT = { type: "certificate", severity: "critical", message: "The SSL certificate expired 2 days ago.", meta: {} };
const PHP = { type: "php_eol", severity: "warning", message: "PHP 7.4 no longer receives security updates.", meta: {} };
const DISK = { type: "disk", severity: "critical", message: "The disk is 94% full.", meta: {} };

const label = (type) => `go:${type}`;

test("what is already broken sorts above what is only a risk", () => {
  const items = issueItems([PHP, CERT], 30, label);
  assert.deepEqual(
    items.map((item) => item.label),
    [CERT.message, PHP.message],
    "the strip has one colour and does not rank inside itself, so order is the only ranking",
  );
});

test("the server's sentence is shown as sent", () => {
  const [item] = issueItems([CERT], 30, label);
  assert.equal(item.label, CERT.message, "it carries a day count this side does not have");
});

test("each kind of problem links to where it is actually fixed", () => {
  const hrefs = Object.fromEntries(
    [CERT, PHP, DISK].map((issue) => [issue.type, issueItems([issue], 30, label)[0].href]),
  );
  assert.equal(hrefs.certificate, "/applications/30/domains?tab=ssl");
  assert.equal(hrefs.php_eol, "/applications/30/php");
  assert.equal(hrefs.disk, "/disk-cleaner", "the disk is the server's, not this site's");
});

test("a kind we have no page for still shows, without a dead action", () => {
  const [item] = issueItems([{ type: "something_new", severity: "warning", message: "Something new." }], 30, label);
  assert.equal(item.label, "Something new.");
  assert.equal(item.href, undefined);
  assert.equal(item.action, undefined, "a button that goes nowhere is worse than no button");
});

test("two problems of one kind get distinct keys", () => {
  const items = issueItems([{ ...CERT }, { ...CERT, message: "Another one." }], 30, label);
  assert.notEqual(items[0].key, items[1].key);
});

test("nothing, junk, and a blank message all yield no rows", () => {
  assert.deepEqual(issueItems(undefined, 30, label), []);
  assert.deepEqual(issueItems(null, 30, label), []);
  assert.deepEqual(issueItems("boom", 30, label), []);
  assert.deepEqual(issueItems([{ type: "dns", severity: "warning", message: "" }], 30, label), []);
});

test("the server's certificate finding replaces the page's guess at one", () => {
  assert.equal(
    localKeysSupersededBy([CERT]).has("ssl"),
    true,
    "two rows about one certificate makes the strip argue with itself",
  );
  assert.equal(localKeysSupersededBy([PHP]).has("ssl"), false, "an unrelated issue suppresses nothing");
  assert.equal(localKeysSupersededBy(undefined).size, 0);
});

test("the schema keeps every field the endpoint sends", () => {
  const parsed = applicationIssuesResponseSchema.parse({
    issues: [{ type: "worker", severity: "critical", message: "queue is stopped", meta: { worker: "queue" } }],
    healthy: false,
  });
  assert.equal(parsed.issues[0].meta.worker, "queue", "meta must survive — Zod strips what is not declared");
  assert.equal(parsed.healthy, false);
});

test("an unknown severity is a warning, never a parse failure", () => {
  const parsed = applicationIssuesResponseSchema.parse({
    issues: [{ type: "dns", severity: "notice", message: "x" }],
    healthy: false,
  });
  assert.equal(parsed.issues[0].severity, "warning", "a new severity must not blank the whole strip");
});

test("the healthy response parses to an empty strip", () => {
  const parsed = applicationIssuesResponseSchema.parse({ issues: [], healthy: true });
  assert.deepEqual(issueItems(parsed.issues, 30, label), []);
});

test("every type we route has an action label in every locale", () => {
  const routed = [...fs.readFileSync("lib/applications/issue-items.js", "utf8").matchAll(/^ {2}(\w+): \(/gm)].map(
    (match) => match[1],
  );
  assert.ok(routed.length >= 6, "the destination map should still be being read");

  for (const locale of ["en", "es", "hi"]) {
    const actions = JSON.parse(fs.readFileSync(`messages/${locale}.json`, "utf8")).applications.attention.issueAction;
    for (const type of routed) {
      assert.ok(actions?.[type], `${locale} is missing an action label for ${type}`);
    }
  }
});

test("the page shows the server's findings and drops the row they supersede", () => {
  const page = fs.readFileSync("app/(app)/applications/[application]/page.jsx", "utf8");
  assert.match(page, /getApplicationIssues\(id\)/);
  assert.match(page, /\.\.\.issueItems\(/, "the server's rows come first");
  assert.match(page, /!superseded\.has\(item\.key\)/);
  assert.match(page, /catch\(\(\) => \(\{ issues: \[\], healthy: true \}\)\)/, "a failed check must not break the page");
});

test("a row with no action does not reach into an undefined href", () => {
  const strip = fs.readFileSync("components/applications/attention-strip.jsx", "utf8");
  assert.match(
    strip,
    /item\.action && item\.href \?/,
    "an issue type we have no page for arrives without an href — `href.startsWith` on it throws the whole page",
  );
});

test("each finding sits with its own action, not in a joined sentence", () => {
  const strip = fs.readFileSync("components/applications/attention-strip.jsx", "utf8");
  assert.doesNotMatch(
    strip,
    /\.join\(" · "\)/,
    "the server sends whole sentences; joined, nothing says which button belongs to which",
  );
});
