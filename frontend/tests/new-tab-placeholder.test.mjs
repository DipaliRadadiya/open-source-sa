import { test } from "node:test";
import assert from "node:assert/strict";
import {
  escapeHtml,
  placeholderDocument,
} from "../lib/browser/new-tab.js";

test("escapes the characters that would end the paragraph early", () => {
  assert.equal(
    escapeHtml('<img src=x onerror="a">'),
    "&lt;img src=x onerror=&quot;a&quot;&gt;",
  );
  assert.equal(escapeHtml("Tom & Jerry's"), "Tom &amp; Jerry&#39;s");
});

test("does not double-encode an entity it just produced", () => {
  // A single character-by-character pass cannot revisit its own output. A
  // naive sequence of replaces — & first, then the rest — would turn this
  // into &amp;amp;lt;.
  assert.equal(escapeHtml("&<"), "&amp;&lt;");
});

test("survives a missing or empty message rather than printing undefined", () => {
  // next-intl returns the key when a translation is missing, but a caller
  // passing nothing should not put the word "undefined" in a user's tab.
  assert.equal(escapeHtml(undefined), "");
  assert.equal(escapeHtml(null), "");
});

test("puts the translated message in the document", () => {
  const html = placeholderDocument("Signing you in to phpMyAdmin…", "phpMyAdmin");

  assert.ok(html.includes("Signing you in to phpMyAdmin…"));
});

test("titles the tab, so it is not labelled about:blank", () => {
  // The visible symptom being fixed: a tab with no title and no content.
  assert.ok(placeholderDocument("x", "phpMyAdmin").includes("<title>phpMyAdmin</title>"));
  // Shared with Magic Login now, so the title is the caller's, not a constant.
  assert.ok(placeholderDocument("x", "Magic Login").includes("<title>Magic Login</title>"));
});

test("escapes the title too, not only the message", () => {
  // Both are translated text, and one of them used to be a literal — which is
  // exactly the kind of difference that stops being true after a move.
  assert.ok(placeholderDocument("x", "<b>&").includes("<title>&lt;b&gt;&amp;</title>"));
});

test("carries a dark-scheme rule, since it opens over a dark panel", () => {
  assert.ok(placeholderDocument("x", "t").includes("prefers-color-scheme:dark"));
});

test("requests nothing over the network", () => {
  // It is replaced within a second. Anything it fetched would still be in
  // flight when the document went away — and a blocked request is a slower
  // blank tab than no request at all.
  const html = placeholderDocument("x", "t");

  for (const attribute of ["<img", "<script", "<link", "url("]) {
    assert.ok(
      !html.includes(attribute),
      `placeholder must not contain ${attribute}`,
    );
  }
});

test("an injected message cannot break out of the document", () => {
  const html = placeholderDocument("</p><script>alert(1)</script>");

  assert.ok(!html.includes("<script>alert(1)</script>"));
  assert.ok(html.includes("&lt;script&gt;"));
});
