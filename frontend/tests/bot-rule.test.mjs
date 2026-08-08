import { test } from "node:test";
import assert from "node:assert/strict";
import {
  botRuleError,
  effectiveBlockedBots,
  hasBot,
} from "../lib/schemas/bot-rule.js";

/**
 * These functions are copies of backend behaviour, which is exactly why they
 * are tested: nothing in a build or a lint run can tell that
 * `App\Rules\BotUserAgent` or `AbstractWebServerDriver::botBlockPattern()`
 * moved underneath them. A failure here means the two have drifted.
 */

test("accepts the shapes real crawler tokens take", () => {
  for (const value of ["GPTBot", "Google-Extended", "anthropic-ai", "GPTBot/1.3", "SemrushBot-OCOB"]) {
    assert.equal(botRuleError(value), null, value);
  }
});

test("refuses anything that could not be a user-agent token", () => {
  for (const value of ["has space", 'quote"', "brace{}", "back\\slash", "new\nline", "x"]) {
    assert.equal(botRuleError(value), "invalid", JSON.stringify(value));
  }
});

test("refuses catch-alls, because they match ordinary crawlers", () => {
  // `bot` is matched against the START of the user agent, so it also matches
  // Googlebot and bingbot — the widely-copied nginx snippet's bug.
  for (const value of ["bot", "BOTS", "crawler", "spider", "agent", "mozilla"]) {
    assert.equal(botRuleError(value), "tooBroad", value);
  }
});

test("the charset check runs first, as it does on the backend", () => {
  // `*`, `.` and `a` are on the catch-all list but can never reach it: they
  // fail the shape test before it. Asserted so a reordering of the two checks
  // — which would change which message someone is shown — is visible here.
  for (const value of ["*", ".*", ".", "a"]) {
    assert.equal(botRuleError(value), "invalid", value);
  }
});

test("refuses search engines but allows their training opt-out tokens", () => {
  assert.equal(botRuleError("googlebot"), "searchEngine");
  assert.equal(botRuleError("Applebot"), "searchEngine");
  // The whole point of comparing the whole value rather than a prefix.
  assert.equal(botRuleError("Applebot-Extended"), null);
  assert.equal(botRuleError("Google-Extended"), null);
});

test("an empty field is not an error, just nothing to add", () => {
  assert.equal(botRuleError(""), null);
  assert.equal(botRuleError("   "), null);
  assert.equal(botRuleError(undefined), null);
});

test("membership is case-insensitive, like the vhost match", () => {
  assert.equal(hasBot(["GPTBot"], "gptbot"), true);
  assert.equal(hasBot(["GPTBot"], " GPTBOT "), true);
  assert.equal(hasBot(["GPTBot"], "ClaudeBot"), false);
});

test("effective list is policy + additions", () => {
  assert.deepEqual(
    effectiveBlockedBots(["GPTBot", "CCBot"], ["EvilBot"], []),
    ["GPTBot", "CCBot", "EvilBot"],
  );
});

test("an allow beats the policy AND a contradictory block", () => {
  // The backend subtracts exemptions last: a rule that says allow and one that
  // says block have only one safe resolution.
  assert.deepEqual(effectiveBlockedBots(["GPTBot", "CCBot"], [], ["GPTBot"]), ["CCBot"]);
  assert.deepEqual(effectiveBlockedBots(["CCBot"], ["GPTBot"], ["gptbot"]), ["CCBot"]);
});

test("the same bot is never counted twice", () => {
  assert.deepEqual(effectiveBlockedBots(["GPTBot"], ["gptbot"], []), ["GPTBot"]);
});

test("no policy and no rules blocks nothing", () => {
  assert.deepEqual(effectiveBlockedBots([], [], []), []);
});
