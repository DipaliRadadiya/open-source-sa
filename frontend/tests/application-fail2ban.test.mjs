import { test } from "node:test";
import assert from "node:assert/strict";
import {
  applicationFail2banResponseSchema,
  fail2banConfigFormSchema,
  missingPlaceholders,
} from "../lib/schemas/application-fail2ban.js";

const JAIL = "[{name}]\nenabled = true\nlogpath = {logpath}\nfilter = {filter}\n";
const FILTER = "[{name}]\nfailregex = ^<HOST> .*\nignoreregex =\n";

test("a site that has never been set up parses to a null config, not an empty one", () => {
  const parsed = applicationFail2banResponseSchema.parse({
    fail2ban: null,
    jail_template: JAIL,
    filter_template: FILTER,
  });

  // Null is the whole difference between "never configured" and "configured
  // with blank files", and the screen renders those two states differently.
  assert.equal(parsed.fail2ban, null);
  assert.equal(parsed.jail_template, JAIL);
});

test("a configured site keeps both halves of its config", () => {
  const parsed = applicationFail2banResponseSchema.parse({
    fail2ban: { jail_name: "sVoss-blog", jail_content: JAIL, filter_content: FILTER },
    jail_template: JAIL,
    filter_template: FILTER,
  });

  assert.equal(parsed.fail2ban.jail_name, "sVoss-blog");
  assert.equal(parsed.fail2ban.filter_content, FILTER);
});

test("a response missing the templates does not take the page down", () => {
  const parsed = applicationFail2banResponseSchema.parse({ fail2ban: null });
  assert.equal(parsed.jail_template, "");
  assert.equal(parsed.filter_template, "");
});

test("both files are required — the backend refuses a half-configured jail", () => {
  assert.equal(
    fail2banConfigFormSchema.safeParse({
      jail_config_content: JAIL,
      filter_config_content: FILTER,
    }).success,
    true,
  );

  for (const half of [
    { jail_config_content: JAIL, filter_config_content: "" },
    { jail_config_content: "   ", filter_config_content: FILTER },
  ]) {
    assert.equal(fail2banConfigFormSchema.safeParse(half).success, false);
  }
});

test("content is capped at what the column accepts", () => {
  const tooLong = "a".repeat(65536);
  assert.equal(
    fail2banConfigFormSchema.safeParse({
      jail_config_content: tooLong,
      filter_config_content: FILTER,
    }).success,
    false,
  );
});

test("a placeholder the user deleted is reported, one that was never there is not", () => {
  assert.deepEqual(missingPlaceholders(JAIL, "[{name}]\nlogpath = /var/log/x\n"), [
    "{filter}",
    "{logpath}",
  ]);
  // `{slug}` is absent from both, so it was not lost — it was never offered.
  assert.deepEqual(missingPlaceholders(JAIL, JAIL), []);
});

test("an edit that keeps every placeholder reports nothing", () => {
  assert.deepEqual(
    missingPlaceholders(JAIL, JAIL.replace("enabled = true", "enabled = false")),
    [],
  );
});
