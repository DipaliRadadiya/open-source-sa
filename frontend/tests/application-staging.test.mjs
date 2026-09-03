import { test } from "node:test";
import assert from "node:assert/strict";
import {
  applicationStagingResponseSchema,
  createStagingFormSchema,
  pushStagingFormSchema,
  PUSH_MODES,
} from "../lib/schemas/application-staging.js";

const STAGING = {
  id: 10,
  name: "Company Blog (Staging)",
  domain: "staging-blog.demo.test",
  site_type: "wordpress",
  status: "active",
};

test("a site with no staging copy parses to null, not an empty object", () => {
  const parsed = applicationStagingResponseSchema.parse({ staging: null });
  // The screen shows an empty state for null and a card for a site; an {} in
  // between would render a card describing nothing.
  assert.equal(parsed.staging, null);
});

test("a staging site keeps the fields the card renders", () => {
  const parsed = applicationStagingResponseSchema.parse({ staging: STAGING });
  assert.equal(parsed.staging.domain, "staging-blog.demo.test");
  assert.equal(parsed.staging.id, 10);
  assert.equal(parsed.staging.status, "active");
});

test("a response with no staging key at all still parses", () => {
  assert.equal(applicationStagingResponseSchema.parse({}).staging, null);
});

test("the domain rule matches what CreateStagingRequest accepts", () => {
  for (const domain of ["staging.example.com", "a-b.co", "deep.sub.example.co.uk"]) {
    assert.equal(createStagingFormSchema.safeParse({ domain }).success, true, domain);
  }

  // No scheme, no path, no port, no bare hostname — the server refuses all of
  // these, so the field should say so before the request goes out.
  for (const domain of ["https://example.com", "example.com/path", "example.com:8080", "localhost", ""]) {
    assert.equal(createStagingFormSchema.safeParse({ domain }).success, false, domain);
  }
});

test("the domain is lower-cased and trimmed the way the backend does it", () => {
  const parsed = createStagingFormSchema.parse({ domain: "  STAGING.Example.COM  " });
  assert.equal(parsed.domain, "staging.example.com");
});

test("push refuses to submit without a mode — there is no safe default", () => {
  assert.equal(pushStagingFormSchema.safeParse({}).success, false);
  assert.equal(pushStagingFormSchema.safeParse({ mode: "" }).success, false);
  // "database" used to be the example of a mode that does not exist. It does
  // now, so the invalid case has to be something that genuinely is not one.
  assert.equal(pushStagingFormSchema.safeParse({ mode: "everything" }).success, false);
});

test("every mode the backend accepts is offered, and only those", () => {
  // Ordered by what each one replaces: files, database, both. The list is
  // cross-checked against the backend FormRequest in
  // tests/staging-push-modes.test.mjs, which fails if the two drift apart.
  assert.deepEqual(PUSH_MODES, ["files", "database", "full"]);
  for (const mode of PUSH_MODES) {
    assert.equal(pushStagingFormSchema.safeParse({ mode }).success, true, mode);
  }
});
