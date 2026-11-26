export const SQL_ENGINE_NAMES = ["mysql", "mariadb"];

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

export function findInstallCandidate(engines = []) {
  return findInstallCandidates(engines)[0] ?? null;
}

export function findInstallCandidates(engines = []) {
  if (installingEngineName(engines)) return [];

  const hasSql = Boolean(findPresentSqlEngine(engines));
  const canAdd = (engine) =>
    engine.installable &&
    !engineIsPresent(engine) &&
    engine.install_status !== "installing" &&
    (engine.install_status !== "failed" || engineInstallCanRetry(engine)) &&
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
