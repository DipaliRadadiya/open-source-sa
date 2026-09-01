export const SQL_ENGINE_NAMES = ["mysql", "mariadb"];

// Older APIs did not expose an authoritative retryable flag. Preserve their
// known terminal failures while preferring the nested progress contract when
// it is present.
const LEGACY_NON_RETRYABLE_REASONS = [
  "port_in_use_by_mysql",
  "port_in_use_by_mariadb",
  "root_unreachable",
];

export function isSqlEngine(engine) {
  const name = typeof engine === "string" ? engine : engine?.engine;
  return SQL_ENGINE_NAMES.includes(name);
}

export function engineIsPresent(engine) {
  return Boolean(engine?.installed || engine?.running);
}

export function findPresentSqlEngine(engines = []) {
  return engines.find(
    (engine) => isSqlEngine(engine) && engineIsPresent(engine),
  );
}

export function engineInstallCanRetry(engine) {
  const retryable = engine?.install_progress?.retryable;
  if (typeof retryable === "boolean") return retryable;
  return !LEGACY_NON_RETRYABLE_REASONS.includes(engine?.install_reason);
}

export function installingEngineName(engines = []) {
  return (
    engines.find((engine) => engine.install_status === "installing")?.engine ??
    null
  );
}

/**
 * The next engine the populated page can offer.
 *
 * Failed work wins so Retry cannot be displaced by a fresh candidate. MySQL
 * and MariaDB are identified by engine name rather than a driver value that
 * older capability payloads omitted.
 */
export function findInstallCandidate(engines = []) {
  return findInstallCandidates(engines)[0] ?? null;
}

/**
 * Every engine that could be added right now, retryable failures first.
 *
 * Plural because the button that offers them has to NAME them: with one
 * candidate it reads "Install MongoDB", with several it becomes a menu of
 * names. A generic "Add engine" that then asks which is the step this feature
 * used to have and no longer does.
 */
export function findInstallCandidates(engines = []) {
  if (installingEngineName(engines)) return [];

  const hasSql = Boolean(findPresentSqlEngine(engines));
  const canAdd = (engine) =>
    engine.installable &&
    !engineIsPresent(engine) &&
    engine.install_status !== "installing" &&
    (engine.install_status !== "failed" || engineInstallCanRetry(engine)) &&
    // One SQL engine per server: offering a second is offering a failure.
    !(hasSql && isSqlEngine(engine));

  const addable = engines.filter(canAdd);
  return [
    ...addable.filter((engine) => engine.install_status === "failed"),
    ...addable.filter((engine) => engine.install_status !== "failed"),
  ];
}

export function markEngineInstalling(engines = [], engineName) {
  return engines.map((engine) =>
    engine.engine === engineName
      ? {
          ...engine,
          install_status: "installing",
          install_reason: null,
          install_message: null,
          // A 202 proves the work is queued. Everything after this placeholder
          // comes from the first successful poll rather than a client timer.
          install_progress: {
            status: "installing",
            started_at: null,
            started_at_human: null,
            reason: null,
            message: null,
            reference: null,
            current_step: "queued",
            current_step_title: null,
            output: null,
            retryable: false,
          },
        }
      : engine,
  );
}
