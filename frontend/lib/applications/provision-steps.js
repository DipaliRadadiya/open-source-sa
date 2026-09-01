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
 */
export function provisionStepLabel(step, t, prefix = "") {
  return PROVISION_STEPS.has(step)
    ? t(`${prefix}steps.${step}`)
    : t(`${prefix}unknownStep`);
}
