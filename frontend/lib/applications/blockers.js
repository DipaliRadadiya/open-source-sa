import { databaseBlock } from "./database-readiness.js";
import { runtimeBlocks } from "./runtime-readiness.js";

/**
 * EVERY reason a site type cannot be created here, not just the first, so the
 * user is not sent to fix dependencies one at a time. The backend's
 * `unavailable()` returns only its first blocker, so each blocker is computed
 * independently; the server's blocker only fills a category not computed here,
 * since the local one is more specific (missing vs stopped vs installing).
 */

/** The order blockers are read and fixed in. */
const ORDER = ["web_server", "runtime", "database", "server"];

/**
 * Whether every blocker could be installed from the panel (runtime, database;
 * not a web-server refusal). The picker uses it to let a greyed card still be
 * chosen, which is the way to reach the install prompts.
 */
export function blockersAreFixable(type) {
  const blockers = Array.isArray(type?.blockers) ? type.blockers : [];
  if (blockers.length === 0) return false;
  return blockers.every(isFixable);
}

/**
 * Whether installing something clears this blocker. Includes the server's own
 * runtime/database blockers: a runtime missing entirely is reported only by
 * the API.
 */
function isFixable(blocker) {
  if (blocker.kind === "runtime" || blocker.kind === "database") return true;
  if (blocker.kind !== "server") return false;
  return blocker.category === "runtime" || blocker.category === "database";
}

/** The backend's single blocker in local shape, sentence verbatim, or null. */
function serverBlocker(type) {
  if (type?.available !== false) return null;
  if (!type.unavailable_reason && !type.unavailable_code) return null;

  return {
    kind: type.unavailable_code === "web_server" ? "web_server" : "server",
    code: type.unavailable_code ?? null,
    reason: type.unavailable_reason ?? null,
    // Lets a locally computed blocker of the same category supersede it.
    category: type.unavailable_code ?? null,
    // The runtime the API says is missing entirely, so the card can offer it.
    runtime: type.installable_runtime ?? null,
  };
}

/**
 * Every blocker for one type, most-actionable first.
 *
 * @returns {Array<object>} empty when the type can be created here
 */
export function typeBlockers({ type, runtimes, engines } = {}) {
  const ours = [
    ...runtimeBlocks({ type, ...(runtimes ?? {}) }),
    databaseBlock({ type, ...(engines ?? {}) }),
  ].filter(Boolean);

  const server = serverBlocker(type);
  const covered = new Set(ours.map((blocker) => blocker.kind));
  const all =
    server !== null && !covered.has(server.category) ? [...ours, server] : ours;

  // A web-server blocker is shown alone: nothing installable fixes it.
  const terminal = all.find((blocker) => blocker.kind === "web_server");
  const list = terminal ? [terminal] : all;

  return list.sort((a, b) => ORDER.indexOf(a.kind) - ORDER.indexOf(b.kind));
}

/**
 * The catalogue with every blocked type marked, in one pass.
 * `available` / `unavailable_code` / `unavailable_reason` carry the primary
 * blocker; `blockers` is the full list the picker reads.
 */
export function withAvailability(siteTypes, context, reasonFor) {
  return (Array.isArray(siteTypes) ? siteTypes : []).map((type) => {
    const blockers = typeBlockers({ type, ...(context ?? {}) });
    if (blockers.length === 0) return type;

    const [primary] = blockers;

    return {
      ...type,
      available: false,
      unavailable_code: primary.kind === "runtime" ? "runtime" : (primary.code ?? primary.kind),
      unavailable_reason: primary.reason ?? reasonFor(primary),
      blockers: blockers.map((blocker) => ({
        ...blocker,
        reason: blocker.reason ?? reasonFor(blocker),
      })),
      // Kept only when the server blocks; a local runtime blocker means the
      // runtime is installed in the wrong version.
      installable_runtime: type.available === false ? (type.installable_runtime ?? null) : null,
    };
  });
}
