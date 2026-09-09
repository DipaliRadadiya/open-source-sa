import { SQL_ENGINE_NAMES } from "../databases/install-lifecycle.js";

/**
 * Whether this server can back a site that needs a database.
 *
 * A WordPress install on a server with no engine gets all the way through the
 * form, provisions, and fails — and the failure names a driver, not the thing
 * the reader has to go and do. This turns that into a sentence on the form,
 * before anyone fills it in.
 *
 * `noDatabaseEngine` below answers the server-wide question — "is there an
 * engine at all" — and `databaseBlock` answers the per-type one the catalogue
 * cannot: whether the engines this server has are engines THIS site type can
 * actually use. The two are separate because a MongoDB-only server has an
 * engine and still cannot host WordPress.
 */

/**
 * True only when we positively know there is nothing to connect to.
 *
 * A failed lookup is not a missing engine: the fetch says nothing about the
 * server, and warning on it would put a red line on the create form every time
 * one endpoint has a wobble.
 */
export function noDatabaseEngine({ engines, failed } = {}) {
  if (failed) return false;
  if (!Array.isArray(engines)) return false;
  // An empty list means the API knows of no engines at all — same answer.
  return engines.every((engine) => engine?.installed !== true);
}

/**
 * An engine mid-install counts as "coming", not "missing".
 *
 * Someone who has just pressed Install on the databases page and walked over
 * here should not be sent back to press it again.
 */
export function engineInstalling({ engines } = {}) {
  // `install_status`, not `install_state`. The schema documents the values:
  // "installing" | "failed" | null, and never "installed" — a finished install
  // deletes its progress row, so `installed` is the only thing that says done.
  return (Array.isArray(engines) ? engines : []).some(
    (engine) => engine?.install_status === "installing",
  );
}

/**
 * The engines a site type can be installed on, or null when nothing constrains
 * it.
 *
 * The catalogue ships `accepted_engines` now, so it is the answer whenever it
 * is present — including when it is EMPTY, which is a real answer and not a
 * missing one. The backend sends `[]` for a type with no installer, and its
 * own check treats that as nothing to verify: a custom PHP site brings its own
 * arrangements and the panel has no list to hold it to. Reading `[]` as "fall
 * back to SQL" would invent a requirement the backend does not have and grey
 * out a type it is perfectly happy to create.
 *
 * The fallback is only for an API that has not shipped the field yet — the
 * frontend and backend deploy separately, and a panel pointed at an older one
 * is the case this whole check exists for. There it is not a guess: that
 * backend had exactly two engine lists, MongoDB alone for NodeBB and MySQL or
 * MariaDB for everything else, and it already reported the MongoDB-only types
 * as unavailable itself.
 */
export function acceptedEngines(type) {
  const declared = type?.accepted_engines;
  if (!Array.isArray(declared)) return SQL_ENGINE_NAMES;
  return declared.length > 0 ? declared : null;
}

/**
 * Why this site type cannot be created here, or null when it can.
 *
 * The gap this closes: the backend skips its own engine check for anything
 * that accepts MySQL or MariaDB, so on a MongoDB-only server WordPress reports
 * itself available, and the form only fails after it is filled in and the site
 * is half provisioned. Every type that needs a database is affected, not just
 * WordPress.
 *
 * Three states rather than one, because they need three different actions:
 * an engine that is missing has to be installed, one that is installing only
 * has to be waited for, and one that is installed but unreachable is a service
 * to start — and telling someone to install what they already have is worse
 * than saying nothing.
 */
export function databaseBlock({ type, engines, failed } = {}) {
  // A failed lookup says nothing about the server. Blocking the catalogue on
  // one endpoint's wobble is a worse failure than the one this prevents.
  if (failed) return null;
  if (!type?.needs_database) return null;
  // Already blocked, with the backend's own reason. Two answers to the same
  // question is how they end up disagreeing.
  if (type.available === false) return null;

  const list = Array.isArray(engines) ? engines : [];
  if (list.length === 0) return null;

  // Null means the catalogue named no engines for this type, which is an
  // answer: there is nothing to hold it to, so there is nothing to block on.
  const accepted = acceptedEngines(type);
  if (accepted === null) return null;

  const found = accepted.map((name) => list.find((engine) => engine?.engine === name));

  // `installed` is "present on the server", `running` is "we can talk to it".
  // The backend needs both before it will create a database, so both are what
  // "usable" means here.
  if (found.some((engine) => engine?.installed === true && engine?.running === true)) return null;

  if (found.some((engine) => engine?.install_status === "installing")) {
    return { state: "installing", engines: accepted };
  }
  if (found.some((engine) => engine?.installed === true)) {
    return { state: "stopped", engines: accepted };
  }
  return { state: "missing", engines: accepted };
}

/**
 * The catalogue with the unusable types marked, in the shape the picker
 * already renders.
 *
 * Deliberately reuses `available` / `unavailable_reason` / `unavailable_code`
 * rather than adding a parallel flag: the grid, the greying, the reason line
 * and the install link all exist and work, and a second mechanism beside them
 * is how one gets forgotten.
 */
export function withDatabaseAvailability(siteTypes, engines, reasonFor) {
  return (Array.isArray(siteTypes) ? siteTypes : []).map((type) => {
    const block = databaseBlock({ type, ...(engines ?? {}) });
    if (block === null) return type;

    return {
      ...type,
      available: false,
      unavailable_code: "database",
      unavailable_reason: reasonFor(block),
      // Nothing about a runtime. The picker reads this to offer a runtime
      // install, and a database block is not one.
      installable_runtime: null,
    };
  });
}
