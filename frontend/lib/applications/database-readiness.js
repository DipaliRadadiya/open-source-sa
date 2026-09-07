/**
 * Whether this server can back a site that needs a database.
 *
 * A WordPress install on a server with no engine gets all the way through the
 * form, provisions, and fails — and the failure names a driver, not the thing
 * the reader has to go and do. This turns that into a sentence on the form,
 * before anyone fills it in.
 *
 * DELIBERATELY NOT PER SITE TYPE. Nothing in the API says which types need a
 * database: not the site-type catalogue, not the field list, not the type
 * classes. The only honest question the frontend can ask is "does this server
 * have an engine at all", so that is the question it asks — and it stays quiet
 * whenever the answer is yes, which on a normal server is always.
 *
 * The alternative was a hardcoded list of type names in the frontend, which
 * would be wrong the first time a type is added and wrong silently. If the
 * backend ever ships a `needs_database` flag on the catalogue, this becomes a
 * per-type check and the shape here does not have to change.
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
