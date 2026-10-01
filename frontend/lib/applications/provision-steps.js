/**
 * Step identifiers the backend records in `steps[]` and `failed_step`; each
 * gets a translated label. Sources: ApplicationProvisioner::step(), the
 * installers' run() calls, and GitDeployer. Unordered: the API's sequence
 * depends on the site type.
 */
export const PROVISION_STEPS = new Set([
  // Core provisioning.
  "create_directory",
  "placeholder",
  "set_ownership",
  "create_php_pool",
  "harden_php",
  "create_database",
  "write_config",
  "test_config",
  "reload",
  "script",
  "restart_app",
  "restart_workers",
  // Git deploys. `verify` only ever appears in failed_step.
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
  "ensure_account",
  "build",
  "create_admin",
  "schedule_cron",
  "start_app",
  "verify_install",
  "verify_serving",
]);

/**
 * Human label for a step. `prefix` selects the namespace (`applications.details`
 * or `applications`). Unknown steps still go through `t()`, whose fallback
 * (i18n/message-fallback.js) humanises the key; `unknownStep` is only for no step.
 */
export function provisionStepLabel(step, t, prefix = "") {
  if (!step) return t(`${prefix}unknownStep`);
  return t(`${prefix}steps.${step}`);
}
