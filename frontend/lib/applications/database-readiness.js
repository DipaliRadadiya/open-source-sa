import { SQL_ENGINE_NAMES } from "../databases/install-lifecycle.js";

/**
 * Whether this server can back a site that needs a database, said on the form
 * before provisioning fails. `noDatabaseEngine` is server-wide; `databaseBlock`
 * is per type (a MongoDB-only server cannot host WordPress).
 */

/** True only when it is known there is no engine; a failed lookup is not that. */
export function noDatabaseEngine({ engines, failed } = {}) {
  if (failed) return false;
  if (!Array.isArray(engines)) return false;
  return engines.every((engine) => engine?.installed !== true);
}

/** An engine mid-install counts as "coming", not "missing". */
export function engineInstalling({ engines } = {}) {
  // `install_status` is "installing" | "failed" | null, never "installed";
  // only `installed` says done.
  return (Array.isArray(engines) ? engines : []).some(
    (engine) => engine?.install_status === "installing",
  );
}

/**
 * The engines a site type can be installed on, or null when unconstrained.
 * `accepted_engines` wins when present, and `[]` is a real answer (nothing to
 * check), not a missing one. The fallback only covers older backends without
 * the field: MongoDB for NodeBB, MySQL/MariaDB for everything else.
 */
export function acceptedEngines(type) {
  const declared = type?.accepted_engines;
  if (!Array.isArray(declared)) return SQL_ENGINE_NAMES;
  return declared.length > 0 ? declared : null;
}

/**
 * Why this site type cannot be created here, or null when it can. The backend
 * skips its engine check for MySQL/MariaDB types, so a MongoDB-only server
 * reports WordPress available. Three states, three actions: missing (install),
 * installing (wait), installed but not running (start). Does not return early
 * for backend-blocked types; `blockers.js` merges every check.
 */
export function databaseBlock({ type, engines, failed } = {}) {
  // A failed lookup must not block the catalogue.
  if (failed) return null;
  if (!type?.needs_database) return null;

  const list = Array.isArray(engines) ? engines : [];
  if (list.length === 0) return null;

  // Null: no engine constraint for this type.
  const accepted = acceptedEngines(type);
  if (accepted === null) return null;

  const found = accepted.map((name) => list.find((engine) => engine?.engine === name));

  // The backend needs an engine both installed and running.
  if (found.some((engine) => engine?.installed === true && engine?.running === true)) return null;

  if (found.some((engine) => engine?.install_status === "installing")) {
    return { kind: "database", state: "installing", engines: accepted };
  }
  if (found.some((engine) => engine?.installed === true)) {
    return { kind: "database", state: "stopped", engines: accepted };
  }
  return { kind: "database", state: "missing", engines: accepted };
}

// Marking the catalogue happens in `blockers.js`, which sees every check.
