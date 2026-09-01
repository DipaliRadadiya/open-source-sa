import test from "node:test";
import assert from "node:assert/strict";
import { readFileSync } from "node:fs";

import { PROVISION_STEPS, provisionStepLabel } from "../lib/applications/provision-steps.js";

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

test("a step the backend adds later reads as prose, never as an identifier", () => {
  const t = (key) => key.split(".").at(-1);
  assert.equal(provisionStepLabel("install_cache", t), "install_cache");
  assert.equal(provisionStepLabel("brand_new_step", t), "unknownStep");
  assert.equal(provisionStepLabel(null, t), "unknownStep");
});
