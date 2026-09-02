/**
 * The provisioning step identifiers the backend records.
 *
 * `steps[]` and `failed_step` carry raw internal names — `create_php_pool`,
 * `trust_domain`, `set_ownership` — and the UI was printing them verbatim.
 * They are keys, not copy, so every one gets a translated label; anything not
 * on this list falls back to a generic phrase rather than leaking an
 * identifier the moment the backend adds a step.
 *
 * Sources: ApplicationProvisioner::step(), the installers' run() calls, and
 * GitDeployer — a deploy records into the same `steps[]` field, so the two
 * screens share one catalog rather than one of them humanising raw keys.
 * The list is unordered on purpose — which steps run, and in what order,
 * depends on the site type, so the API's own sequence is the only truth.
 */
export const PROVISION_STEPS = new Set([
  // Core provisioning.
  "create_directory",
  "placeholder",
  "set_ownership",
  "create_php_pool",
  "create_database",
  "write_config",
  "test_config",
  "reload",
  "script",
  "restart_app",
  "restart_workers",
  // Git deploys. `verify` never appears in steps[] — it is only ever written
  // to failed_step, because a site that answers is not worth a row of its own.
  "init",
  "fetch",
  "checkout",
  "seed_env",
  "verify",
  // One-click installers.
  "download",
  "extract",
  "configure",
  "harden",
  "install_app",
  "install_cache",
  "install_cli",
  "set_password",
  "set_timezone",
  "trust_domain",
]);

/**
 * Human label for a step. `prefix` bridges the two namespaces this is called
 * from — the card translates under `applications.details`, the list and row
 * actions under `applications`, and both need the same words.
 *
 * An unrecognised step is handed to `t()` anyway rather than swapped for the
 * generic phrase. The panel now supplies a fallback for every missing key
 * (i18n/message-fallback.js), so a step we have no wording for reads as
 * "Brand new step" instead of leaking `brand_new_step` — and, more to the
 * point, instead of "Completed a step", which the failure sentence rendered as
 * **"Stopped at: Completed a step"**. A message that contradicts itself is
 * worse than one that is merely unpolished.
 *
 * `unknownStep` survives for the case it was actually right about: no step at
 * all. A failure that names nothing cannot be described by naming something.
 */
export function provisionStepLabel(step, t, prefix = "") {
  if (!step) return t(`${prefix}unknownStep`);
  return t(`${prefix}steps.${step}`);
}
