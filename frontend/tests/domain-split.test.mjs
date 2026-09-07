import test from "node:test";
import assert from "node:assert/strict";
import { splitDomain } from "../lib/format/domain.js";

test("the registrable domain is what gets protected", () => {
  /*
   * The bug: a plain `truncate` keeps the beginning, which on a domain is the
   * least identifying part. At 390px seven sites all rendered as
   * "a-very-long-customer-subdomain-for-testi…" — the same string seven times,
   * on the field that tells them apart.
   */
  assert.deepEqual(splitDomain("a-very-long-subdomain.example.com"), {
    head: "a-very-long-subdomain",
    tail: ".example.com",
  });
  assert.deepEqual(splitDomain("staging.wordpress.23-172-120-73.nip.io"), {
    head: "staging.wordpress.23-172-120-73",
    tail: ".nip.io",
  });
});

test("a two-part suffix keeps the name, not just the suffix", () => {
  /*
   * The first attempt took the last two labels always, so example.co.uk kept
   * ".co.uk" — the public suffix and nothing else, which identifies a site no
   * better than the truncation being fixed.
   */
  assert.deepEqual(splitDomain("shop.example.co.uk"), {
    head: "shop",
    tail: ".example.co.uk",
  });
  assert.deepEqual(splitDomain("a.b.example.com.au"), {
    head: "a.b",
    tail: ".example.com.au",
  });
  // Already just the registrable domain: nothing to split.
  assert.deepEqual(splitDomain("example.co.uk"), { head: "", tail: "example.co.uk" });
  // Case is not a way to dodge the list.
  assert.deepEqual(splitDomain("Shop.Example.CO.UK").tail, ".Example.CO.UK");
});

test("a short domain is left whole", () => {
  // Splitting "example.com" into "" + "example.com" would add an empty element
  // for nothing; the caller renders the plain truncating span instead.
  assert.deepEqual(splitDomain("example.com"), { head: "", tail: "example.com" });
  assert.deepEqual(splitDomain("localhost"), { head: "", tail: "localhost" });
});

test("nothing in, nothing out", () => {
  assert.deepEqual(splitDomain(""), { head: "", tail: "" });
  assert.deepEqual(splitDomain(null), { head: "", tail: "" });
  assert.deepEqual(splitDomain(undefined), { head: "", tail: "" });
  assert.deepEqual(splitDomain("   "), { head: "", tail: "" });
});

test("head and tail always rebuild the original", () => {
  // The split is presentational: whatever it does, the two halves joined must
  // be the domain the API sent, or the screen is showing a different address.
  for (const domain of [
    "example.com",
    "www.example.com",
    "a.b.c.d.example.co.uk",
    "prestashop.23-172-120-73.nip.io",
    "shop.example.co.uk",
    "example.co.uk",
    "localhost",
  ]) {
    const { head, tail } = splitDomain(domain);
    assert.equal(head + tail, domain, domain);
  }
});
