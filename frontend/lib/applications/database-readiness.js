import { SQL_ENGINE_NAMES } from "../databases/install-lifecycle.js";

export function noDatabaseEngine({ engines, failed } = {}) {
  if (failed) return false;
  if (!Array.isArray(engines)) return false;
  return engines.every((engine) => engine?.installed !== true);
}

export function engineInstalling({ engines } = {}) {
  return (Array.isArray(engines) ? engines : []).some(
    (engine) => engine?.install_status === "installing",
  );
}

export function acceptedEngines(type) {
  const declared = type?.accepted_engines;
  if (!Array.isArray(declared)) return SQL_ENGINE_NAMES;
  return declared.length > 0 ? declared : null;
}

export function databaseBlock({ type, engines, failed } = {}) {
  if (failed) return null;
  if (!type?.needs_database) return null;

  const list = Array.isArray(engines) ? engines : [];
  if (list.length === 0) return null;

  const accepted = acceptedEngines(type);
  if (accepted === null) return null;

  const found = accepted.map((name) => list.find((engine) => engine?.engine === name));

  if (found.some((engine) => engine?.installed === true && engine?.running === true)) return null;

  if (found.some((engine) => engine?.install_status === "installing")) {
    return { kind: "database", state: "installing", engines: accepted };
  }
  if (found.some((engine) => engine?.installed === true)) {
    return { kind: "database", state: "stopped", engines: accepted };
  }
  return { kind: "database", state: "missing", engines: accepted };
}

