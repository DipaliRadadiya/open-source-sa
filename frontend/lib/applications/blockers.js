import { databaseBlock } from "./database-readiness.js";
import { runtimeBlocks } from "./runtime-readiness.js";

/**
 * EVERY reason a site type cannot be created here, not just the first one.
 *
 * A user reported the behaviour this replaces, and described it exactly: they
 * were told to install MySQL, installed it, came back, and were then told to
 * install Node. "Requiring the user to go back and forth between different
 * sections and discover dependencies one at a time."
 *
 * It was one-at-a-time in three separate places, each hiding the next:
 *
 *   - the backend's `unavailable()` is a chain of early returns — runtime,
 *     then database, then web server — so the API reports one blocker and the
 *     panel cannot see past it;
 *   - our database check skipped any type already marked unavailable, which
 *     after the runtime check ran was every runtime-blocked type;
 *   - our runtime check returned on the first failing runtime, so a type
 *     wanting both PHP and Node only ever admitted to PHP.
 *
 * Each skip was defensible on its own — they were there so the panel would not
 * argue with the server, or with itself. Together they made the create page
 * hand out one errand per visit.
 *
 * The rule here instead: compute every blocker independently, then let the
 * server's own blocker fill a category we have no answer for. A category we
 * DID compute wins, because our version of it is the more specific — we know
 * whether an engine is missing, stopped or still installing, and the API's
 * sentence for all three is "not installed".
 */

/** Blockers ordered the way they should be read and fixed. */
const ORDER = ["web_server", "runtime", "database", "server"];

/**
 * Whether everything blocking this type could be installed from the panel.
 *
 * A runtime and a database engine each have an install endpoint and a screen
 * that owns it. A web-server refusal has neither, and a type held up by one is
 * not "blocked until you install something" — it is not on offer here at all.
 *
 * Read by the picker to decide whether a greyed card can still be CHOSEN: on a
 * server with no engine and no Node, every card that needs one is dead, so the
 * screen that offers to install them cannot be reached — reaching it means
 * picking the very application the server cannot host yet.
 */
export function blockersAreFixable(type) {
  const blockers = Array.isArray(type?.blockers) ? type.blockers : [];
  if (blockers.length === 0) return false;
  return blockers.every(isFixable);
}

/**
 * One blocker, and whether installing something clears it.
 *
 * The server's own blocker counts when it is talking about a runtime or a
 * database — and it usually IS. On a server with no Node at all our runtime
 * check stays silent (no installed versions to range-check), so "this server
 * does not have Node.js" arrives as the API's sentence, category `runtime`.
 * Reading only our own blockers made exactly the cards most in need of an
 * install button the ones that could not reach it.
 */
function isFixable(blocker) {
  if (blocker.kind === "runtime" || blocker.kind === "database") return true;
  if (blocker.kind !== "server") return false;
  return blocker.category === "runtime" || blocker.category === "database";
}

/**
 * The backend's single blocker as one of ours, or null.
 *
 * Its sentence is kept verbatim. This is the case for a block we cannot
 * compute — a web server that refuses the type, or anything added upstream
 * tomorrow — and inventing a short label for a sentence we have not seen is
 * how a card ends up confidently wrong.
 */
function serverBlocker(type) {
  if (type?.available !== false) return null;
  if (!type.unavailable_reason && !type.unavailable_code) return null;

  return {
    kind: type.unavailable_code === "web_server" ? "web_server" : "server",
    code: type.unavailable_code ?? null,
    reason: type.unavailable_reason ?? null,
    // Which category the server was talking about, so a blocker we computed
    // ourselves can supersede it rather than sit beside it saying the same
    // thing in different words.
    category: type.unavailable_code ?? null,
    /*
     * Which runtime, when the server says a runtime is missing ENTIRELY.
     *
     * Our own runtime check cannot produce this one: it asks whether any
     * installed version is in range, and on a server with no Node at all there
     * is no list to compare — a different problem, reported here instead. The
     * API names the runtime in `installable_runtime` precisely so a card can
     * offer to fix itself, and this is the most fixable blocker there is.
     */
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

  /*
   * A web server that will not serve this type ends the list.
   *
   * Nothing installable fixes it, so listing a database to install underneath
   * is an errand that leads nowhere — the one case where showing every
   * blocker is less honest than showing one.
   */
  const terminal = all.find((blocker) => blocker.kind === "web_server");
  const list = terminal ? [terminal] : all;

  return list.sort((a, b) => ORDER.indexOf(a.kind) - ORDER.indexOf(b.kind));
}

/**
 * The catalogue with every blocked type marked, in the shape the picker
 * already renders.
 *
 * Replaces the two separate decorators it grew out of. They ran in sequence
 * and each deferred to whatever the other had already decided, which is the
 * drip-feed itself — two passes cannot produce one list.
 *
 * `available` / `unavailable_code` / `unavailable_reason` still carry the
 * PRIMARY blocker, unchanged, so everything reading them keeps working.
 * `blockers` is the whole list, and the picker reads that.
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
      /*
       * The server's offer to install a runtime survives only when the server
       * is the one blocking. Our own runtime blocker is the opposite case —
       * the runtime IS installed, in a version this type cannot use — and
       * offering to install the runtime there answers a question nobody asked.
       */
      installable_runtime: type.available === false ? (type.installable_runtime ?? null) : null,
    };
  });
}
