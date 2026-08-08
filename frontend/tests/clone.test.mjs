import { test } from "node:test";
import assert from "node:assert/strict";
import {
  CLONE_DROPS,
  cloneBlockedReason,
  cloneCarries,
  cloneFormSchema,
  suggestCloneDomain,
} from "../lib/schemas/clone.js";

const domainError = (value) => {
  const result = cloneFormSchema.shape.domain.safeParse(value);
  return result.success ? null : result.error.issues[0].message;
};

/**
 * The domain rule is a copy of `CreateCloneRequest`'s regex, and the submit
 * button is gated on it. A rule that drifts laxer promises an acceptance the
 * server then refuses; one that drifts stricter withholds a button that would
 * have worked.
 */

test("accepts domains the API would accept", () => {
  for (const value of ["copy.blog.demo.test", "a-b.example.com", "x.co"]) {
    assert.equal(domainError(value), null, value);
  }
});

test("normalises before judging, exactly as the backend does", () => {
  const parsed = cloneFormSchema.shape.domain.parse("  COPY.Blog.Demo.TEST  ");
  assert.equal(parsed, "copy.blog.demo.test");
});

test("refuses anything without a real TLD, or with junk in it", () => {
  for (const value of ["", "localhost", "example.", "exa mple.com", "under_score.com", "a.b"]) {
    assert.notEqual(domainError(value), null, value);
  }
});

test("suggests copy.<domain> and never one already in use", () => {
  assert.equal(suggestCloneDomain("blog.demo.test", []), "copy.blog.demo.test");
  assert.equal(
    suggestCloneDomain("blog.demo.test", ["copy.blog.demo.test"]),
    "copy-2.blog.demo.test",
  );
  assert.equal(
    suggestCloneDomain("blog.demo.test", ["COPY.blog.demo.test", "copy-2.blog.demo.test"]),
    "copy-3.blog.demo.test",
  );
  assert.equal(suggestCloneDomain("", []), "");
});

test("what it suggests is always something the form would accept", () => {
  assert.equal(domainError(suggestCloneDomain("blog.demo.test", [])), null);
});

test("a site that cannot be cloned is refused before anyone types", () => {
  const wordpress = { name: "wordpress", needs_database: true };
  const laravel = { name: "laravel", needs_database: true };
  const static_ = { name: "static", needs_database: false };

  assert.equal(cloneBlockedReason({ status: "failed" }, static_), "sourceFailed");
  assert.equal(cloneBlockedReason({ status: "installing" }, static_), "provisioning");
  // Needs a database, no strategy on the backend to rewrite its URLs.
  assert.equal(cloneBlockedReason({ status: "active" }, laravel), "noRecipe");
  assert.equal(cloneBlockedReason({ status: "active" }, wordpress), null);
  assert.equal(cloneBlockedReason({ status: "active" }, static_), null);
});

test("the database line only appears for types that have one", () => {
  assert.ok(cloneCarries({ needs_database: true }).includes("database"));
  assert.ok(!cloneCarries({ needs_database: false }).includes("database"));
  assert.ok(!cloneCarries(null).includes("database"));
});

test("the omissions include the two that are security surprises", () => {
  assert.ok(CLONE_DROPS.includes("passwordProtection"));
  assert.ok(CLONE_DROPS.includes("ssl"));
});
