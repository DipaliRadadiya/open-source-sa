import { databaseBlock } from "./database-readiness.js";
import { runtimeBlocks } from "./runtime-readiness.js";

const ORDER = ["web_server", "runtime", "database", "server"];

export function blockersAreFixable(type) {
  const blockers = Array.isArray(type?.blockers) ? type.blockers : [];
  if (blockers.length === 0) return false;
  return blockers.every(isFixable);
}

function isFixable(blocker) {
  if (blocker.kind === "runtime" || blocker.kind === "database") return true;
  if (blocker.kind !== "server") return false;
  return blocker.category === "runtime" || blocker.category === "database";
}

function serverBlocker(type) {
  if (type?.available !== false) return null;
  if (!type.unavailable_reason && !type.unavailable_code) return null;

  return {
    kind: type.unavailable_code === "web_server" ? "web_server" : "server",
    code: type.unavailable_code ?? null,
    reason: type.unavailable_reason ?? null,
    category: type.unavailable_code ?? null,
    runtime: type.installable_runtime ?? null,
  };
}

export function typeBlockers({ type, runtimes, engines } = {}) {
  const ours = [
    ...runtimeBlocks({ type, ...(runtimes ?? {}) }),
    databaseBlock({ type, ...(engines ?? {}) }),
  ].filter(Boolean);

  const server = serverBlocker(type);
  const covered = new Set(ours.map((blocker) => blocker.kind));
  const all =
    server !== null && !covered.has(server.category) ? [...ours, server] : ours;

  const terminal = all.find((blocker) => blocker.kind === "web_server");
  const list = terminal ? [terminal] : all;

  return list.sort((a, b) => ORDER.indexOf(a.kind) - ORDER.indexOf(b.kind));
}

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
      installable_runtime: type.available === false ? (type.installable_runtime ?? null) : null,
    };
  });
}
