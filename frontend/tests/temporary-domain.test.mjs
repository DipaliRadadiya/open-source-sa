import { test } from "node:test";
import assert from "node:assert/strict";
import {
  ipToLabel,
  temporaryDomain,
  toDomainLabel,
} from "../lib/applications/temporary-domain.js";

const IP = "167.233.229.184";

test("a plain name becomes the label it looks like", () => {
  assert.equal(toDomainLabel("myblog"), "myblog");
  assert.equal(toDomainLabel("My Blog"), "my-blog");
  assert.equal(toDomainLabel("  Shop 2024  "), "shop-2024");
});

test("punctuation collapses instead of producing double or edge hyphens", () => {
  // "a--b" and "-a" are not legal labels, so neither may survive.
  assert.equal(toDomainLabel("My   Blog!!!"), "my-blog");
  assert.equal(toDomainLabel("...leading and trailing..."), "leading-and-trailing");
  assert.equal(toDomainLabel("a_b.c"), "a-b-c");
});

test("accents are folded rather than dropped to hyphens", () => {
  assert.equal(toDomainLabel("Café"), "cafe");
  assert.equal(toDomainLabel("Münster"), "munster");
});

test("a name with nothing usable in it yields no label", () => {
  // The caller shows a hint instead of a half-built domain.
  for (const name of ["", "   ", "!!!", "。。。", null, undefined]) {
    assert.equal(toDomainLabel(name), "");
  }
});

test("a label is cut to 63 characters and never ends on a hyphen", () => {
  const label = toDomainLabel("a".repeat(70));
  assert.equal(label.length, 63);

  // Cutting mid-word can land on a hyphen; that would be an illegal label.
  const cut = toDomainLabel(`${"a".repeat(62)} tail`);
  assert.equal(cut.endsWith("-"), false);
  assert.ok(cut.length <= 63);
});

test("the IP is written with dashes, matching the panel's own hostname", () => {
  assert.equal(ipToLabel(IP), "167-233-229-184");
  assert.equal(ipToLabel("10.0.0.1"), "10-0-0-1");
});

test("anything that is not an IPv4 gives no host part", () => {
  // IPv6 has no nip.io dashed form here, and a hostname is not an address.
  for (const ip of ["", null, "not-an-ip", "2001:db8::1", "167.233.229"]) {
    assert.equal(ipToLabel(ip), "");
  }
});

test("name and IP together make the domain", () => {
  assert.equal(temporaryDomain("My Blog", IP), "my-blog.167-233-229-184.nip.io");
});

test("with no name yet the domain is still complete, using the default label", () => {
  // The field is filled the moment the option is chosen; an empty box under a
  // control you just switched on reads as broken.
  assert.equal(temporaryDomain("", IP), "site.167-233-229-184.nip.io");
  assert.equal(temporaryDomain("!!!", IP), "site.167-233-229-184.nip.io");
  assert.equal(temporaryDomain(null, IP), "site.167-233-229-184.nip.io");
});

test("a name replaces the default rather than joining it", () => {
  assert.equal(temporaryDomain("blog", IP), "blog.167-233-229-184.nip.io");
});

test("the caller can name the fallback, and a useless one is refused", () => {
  assert.equal(temporaryDomain("", IP, { fallbackLabel: "demo" }), "demo.167-233-229-184.nip.io");
  assert.equal(temporaryDomain("", IP, { fallbackLabel: "!!!" }), null);
});

test("without an address there is no domain at all", () => {
  // `site..nip.io` is not one keystroke from valid — it is nothing.
  assert.equal(temporaryDomain("blog", null), null);
  assert.equal(temporaryDomain("blog", "not-an-ip"), null);
  assert.equal(temporaryDomain("", null), null);
});
