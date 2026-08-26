export const SQL_ENGINE_NAMES = ["mysql", "mariadb"];

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
  if (installingEngineName(engines)) return null;

  const hasSql = Boolean(findPresentSqlEngine(engines));
  const canAdd = (engine) =>
    engine.installable &&
    !engineIsPresent(engine) &&
    engine.install_status !== "installing" &&
    !(hasSql && isSqlEngine(engine));

  return (
    engines.find(
      (engine) => engine.install_status === "failed" && canAdd(engine),
    ) ??
    engines.find(canAdd) ??
    null
  );
}

export function markEngineInstalling(engines = [], engineName) {
  return engines.map((engine) =>
    engine.engine === engineName
      ? {
          ...engine,
          install_status: "installing",
          install_reason: null,
          install_message: null,
        }
      : engine,
  );
}
