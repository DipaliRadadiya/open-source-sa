import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

import { PROVISION_STEPS, provisionStepLabel } from "../lib/applications/provision-steps.js";
import { getMessageFallback } from "../i18n/message-fallback.js";

/**
 * A deploy records into the same `steps[]` field as provisioning, but the five
 * steps only a git deploy runs were never added to the catalog — so the deploy
 * card printed raw keys tidied up by hand ("Seed env", "Set ownership"), which
 * meant Spanish and Hindi read English, and a failure said `failed at "verify"`.
 *
 * Source of truth: GitDeployer's run()/record() calls.
 */
const DEPLOY_STEPS = [
  "init",
  "fetch",
  "checkout",
  "set_ownership",
  "seed_env",
  "script",
  "restart_app",
  "restart_workers",
];

/** Recorded only on failed_step — a site that answers gets no row. */
const DEPLOY_FAILURE_ONLY = ["verify"];

const LOCALES = ["en", "es", "hi"];
const messages = Object.fromEntries(
  LOCALES.map((l) => [
    l,
    JSON.parse(readFileSync(new URL(`../messages/${l}.json`, import.meta.url), "utf8")),
  ]),
);

test("every step a deploy can record has a label", () => {
  for (const step of [...DEPLOY_STEPS, ...DEPLOY_FAILURE_ONLY]) {
    assert.ok(PROVISION_STEPS.has(step), `${step} is missing from the catalog`);
  }
});

test("the labels are translated in every locale, not just English", () => {
  for (const locale of LOCALES) {
    const steps = messages[locale].applications.details.steps;
    for (const step of [...DEPLOY_STEPS, ...DEPLOY_FAILURE_ONLY]) {
      assert.ok(steps[step], `${locale} has no label for ${step}`);
    }
    if (locale !== "en") {
      const en = messages.en.applications.details.steps;
      // Not a general rule — these five are plain prose with no brand or
      // technical token in them, so an identical string means untranslated.
      for (const step of ["init", "fetch", "checkout", "seed_env", "verify"]) {
        assert.notEqual(steps[step], en[step], `${locale} left ${step} in English`);
      }
    }
  }
});

/**
 * Stands in for next-intl: resolve the key against the real English messages,
 * and hand a miss to the real fallback — which is the whole point of the pair.
 */
const translate = (key) => {
  const found = key
    .split(".")
    .reduce((o, part) => (o && typeof o === "object" ? o[part] : undefined), messages.en.applications.details);
  return typeof found === "string" ? found : getMessageFallback({ key });
};

test("a known step uses the wording we chose for it", () => {
  assert.equal(provisionStepLabel("set_ownership", translate), "Setting file permissions");
  assert.equal(provisionStepLabel("verify", translate), "Checking the site responds");
});

test("a step the backend adds later reads as prose, never as an identifier", () => {
  assert.equal(provisionStepLabel("brand_new_step", translate), "Brand new step");
});

test("an unknown step never claims the step succeeded", () => {
  // It used to fall back to `unknownStep` — "Completed a step" — which the
  // failure sentence rendered as "Stopped at: Completed a step".
  assert.notEqual(provisionStepLabel("brand_new_step", translate), "Completed a step");
});

test("no step at all is the one case the generic phrase still fits", () => {
  assert.equal(provisionStepLabel(null, translate), "Completed a step");
  assert.equal(provisionStepLabel(undefined, translate), "Completed a step");
});
