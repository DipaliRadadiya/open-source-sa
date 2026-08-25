import { test } from "node:test";
import assert from "node:assert/strict";
import {
  isValidApplicationDomain,
  suggestApplicationDomain,
} from "../lib/schemas/application.js";

test("accepts hostnames the application can serve", () => {
  for (const domain of [
    "example.com",
    "app.example.com",
    "EXAMPLE.COM",
    "site.167-233-229-184.nip.io",
    "xn--bcher-kva.example",
  ]) {
    assert.equal(isValidApplicationDomain(domain), true, domain);
  }
});

test("rejects URLs, paths, ports, and malformed labels", () => {
  for (const domain of [
    "",
    "localhost",
    "https://example.com",
    "example.com/path",
    "example.com:8080",
    "-app.example.com",
    "app-.example.com",
    "app_name.example.com",
    "example.com.",
  ]) {
    assert.equal(isValidApplicationDomain(domain), false, domain);
  }
});

test("offers a safe hostname for pasted URLs without changing valid input", () => {
  assert.equal(
    suggestApplicationDomain("https://Example.com/path?preview=1"),
    "example.com",
  );
  assert.equal(suggestApplicationDomain("example.com/path"), "example.com");
  assert.equal(suggestApplicationDomain("EXAMPLE.COM"), "example.com");
  assert.equal(suggestApplicationDomain("example.com"), null);
  assert.equal(suggestApplicationDomain("not a domain"), null);
});
