import test from "node:test";
import assert from "node:assert/strict";
import { siteTitleFrom } from "../lib/applications/site-title.js";

test("separators become spaces and each word is capitalised", () => {
  assert.equal(siteTitleFrom("my-shop"), "My Shop");
  assert.equal(siteTitleFrom("acme_blog"), "Acme Blog");
  assert.equal(siteTitleFrom("client.staging.site"), "Client Staging Site");
  assert.equal(siteTitleFrom("my-shop_uk.v2"), "My Shop Uk V2");
});

test("a name already written for humans is passed through", () => {
  /*
   * The rule only splits on characters a title would not contain, so a name
   * typed as prose is already its own title and must not be rewritten. This is
   * the common case once the panel has been used for a while.
   */
  assert.equal(siteTitleFrom("Krishna Consulting"), "Krishna Consulting");
  assert.equal(siteTitleFrom("The Daily Ledger"), "The Daily Ledger");
});

test("existing capitals survive", () => {
  /*
   * Case-folding before capitalising would turn these into "Iphone Repair" and
   * "Acme" — a title the user then has to correct, which is worse than the
   * empty box this replaces. Whatever case they typed is the case they meant.
   */
  assert.equal(siteTitleFrom("iPhone-repair"), "IPhone Repair");
  assert.equal(siteTitleFrom("ACME"), "ACME");
  assert.equal(siteTitleFrom("nextcloud-GmbH"), "Nextcloud GmbH");
});

test("whitespace and repeated separators collapse", () => {
  assert.equal(siteTitleFrom("  my--shop  "), "My Shop");
  assert.equal(siteTitleFrom("a___b"), "A B");
  assert.equal(siteTitleFrom("shop . uk"), "Shop Uk");
});

test("nothing in gives an empty string, never 'undefined'", () => {
  /*
   * The form calls this on every keystroke of Name, including while it is
   * empty. Returning the string "undefined" would write that into a required
   * field and submit it.
   */
  assert.equal(siteTitleFrom(""), "");
  assert.equal(siteTitleFrom(null), "");
  assert.equal(siteTitleFrom(undefined), "");
  assert.equal(siteTitleFrom("   "), "");
  assert.equal(siteTitleFrom("---"), "");
});

test("non-latin names are left alone rather than mangled", () => {
  /*
   * Eight locales ship, and most scripts have no concept of letter case.
   * `toUpperCase` is a no-op there, which is the wanted behaviour — the point
   * is that the separator rule still applies.
   */
  assert.equal(siteTitleFrom("मेरी-दुकान"), "मेरी दुकान");
  assert.equal(siteTitleFrom("お店"), "お店");
});
