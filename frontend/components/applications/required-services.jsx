import { useCallback, useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { useRefresh } from "@/hooks/use-refresh";
import { installEngine } from "@/lib/api/databases";
import { installPhpVersion } from "@/lib/api/php";
import { installNodeVersion } from "@/lib/api/node";
import { apiMessage } from "@/lib/api/error-message";
import { installTarget, rangeLabel } from "@/lib/runtime/version-range";
import { RequiredServicesPanel } from "@/components/applications/required-services-panel";

/**
 * Install what the chosen application needs, from the create form.
 *
 * Uses the existing install endpoints (`POST /databases/engines/{engine}`,
 * `/php/versions`, `/node/versions`): each is an idempotent 202 on a
 * single-worker queue, so firing them all is safe.
 *
 * No local state machine: each row's state is read from the same live data the
 * page renders from, so it cannot disagree with the rest of the page.
 */

const RUNTIME_NAMES = { php: "PHP", node: "Node" };

const ENGINE_LABELS = {
  mysql: "MySQL",
  mariadb: "MariaDB",
  mongodb: "MongoDB",
  postgresql: "PostgreSQL",
};

/**
 * The blockers as rows, each with the exact thing this panel would install.
 * Computed once per chosen application: blockers vanish as they are cleared, so
 * deriving on every render would drop a row the moment it turned green.
 */
function servicesFor(type, { phpInstallable, nodeInstallable }) {
  const blockers = Array.isArray(type?.blockers) ? type.blockers : [];
  const installable = { php: phpInstallable, node: nodeInstallable };

  return blockers.flatMap((blocker) => {
    const database =
      blocker.kind === "database" ||
      (blocker.kind === "server" && blocker.category === "database");

    if (database) {
      const engine = blocker.engines?.[0] ?? type?.accepted_engines?.[0] ?? null;
      if (!engine) return [];
      return [
        {
          key: `database-${engine}`,
          kind: "database",
          engine,
          name: ENGINE_LABELS[engine] ?? engine,
        },
      ];
    }

    const runtime =
      blocker.kind === "runtime"
        ? blocker.runtime
        : blocker.kind === "server" && blocker.category === "runtime"
          ? blocker.runtime
          : null;
    if (!runtime) return [];

    /*
     * Which version to install: the server's `suggest` when a range excluded
     * everything installed, otherwise the lowest installable version that fits
     * (matching `runtime-readiness`). Null means nothing installable fits, a
     * separate state with no button.
     */
    const target = blocker.suggest
      ? { version: blocker.suggest, eol: Boolean(blocker.suggestEol) }
      : installTarget(installable[runtime], blocker.range ?? null);
    const version = target?.version ?? null;
    const label = RUNTIME_NAMES[runtime] ?? runtime;

    return [
      {
        key: `runtime-${runtime}`,
        kind: runtime,
        version,
        // The application's requirement, so the exact version above does not read as
        // what the app demands.
        requirement: blocker.range ? rangeLabel(blocker.range) : null,
        // True when the only usable version is end-of-life; the row must say so.
        eol: Boolean(target?.eol),
        // Never the bare runtime name: "PHP — Not installed" is false on a server
        // running another PHP version.
        name: version ? `${label} ${version}` : blocker.label ? `${label} ${blocker.label}` : label,
      },
    ];
  });
}

/** Where each row's state actually comes from: the server, every time. */
function liveState(row, { engines, phpVersions, nodeVersions, canInstall, errors }) {
  if (errors[row.key]) return "failed";

  if (row.kind === "database") {
    const engine = (engines ?? []).find((item) => item?.engine === row.engine);
    if (engine?.installed === true && engine?.running === true) return "installed";
    if (engine?.install_status === "installing") return "installing";
    if (engine?.install_status === "failed") return "failed";
    /*
     * An engine the panel cannot install here at all (e.g. MongoDB where the
     * vendor ships no build for this OS): `impossible`, not `missing`, so no
     * Install button that can only 422.
     */
    if (engine && engine.installable === false && !engine.installed) return "impossible";
    if (!canInstall.database) return "denied";
    return "missing";
  }

  if (!row.version) return "impossible";

  const versions = (row.kind === "php" ? phpVersions : nodeVersions) ?? [];
  const found = versions.find((item) => item?.version === row.version);
  if (found && (!found.status || found.status === "ready")) return "installed";
  if (found?.status === "installing") return "installing";
  if (found?.status === "failed") return "failed";
  if (!canInstall[row.kind]) return "denied";
  return "missing";
}

const INSTALL = {
  php: (row) => installPhpVersion(row.version),
  node: (row) => installNodeVersion(row.version),
  database: (row) => installEngine(row.engine),
};

const POLL_MS = 4000;

export function RequiredServices({
  type,
  engines = [],
  phpVersions = [],
  nodeVersions = [],
  phpInstallable = [],
  nodeInstallable = [],
  canInstall = {},
}) {
  const { refresh } = useRefresh();
  const [busy, setBusy] = useState(false);
  const [errors, setErrors] = useState({});

  const [rows] = useState(() => servicesFor(type, { phpInstallable, nodeInstallable }));

  const services = useMemo(
    () =>
      rows.map((row) => {
        const state = liveState(row, { engines, phpVersions, nodeVersions, canInstall, errors });

        return {
          ...row,
          state,
          /*
           * The server's own reason when it has one; the generic sentence is about
           * version ranges and does not fit an unavailable engine.
           */
          reason:
            state === "impossible" && row.kind === "database"
              ? ((engines ?? []).find((item) => item?.engine === row.engine)?.unavailable?.reason ??
                null)
              : null,
        };
      }),
    [rows, engines, phpVersions, nodeVersions, canInstall, errors],
  );

  const working = services.some((service) => service.state === "installing");

  /*
   * Poll only while something is running, by refreshing the page rather than
   * fetching here: versions, engines and blockers are computed together on the
   * server, so the card grid above updates too.
   */
  useEffect(() => {
    if (!working) return undefined;
    const timer = setInterval(refresh, POLL_MS);
    return () => clearInterval(timer);
  }, [working, refresh]);

  const start = useCallback(
    async (only) => {
      const queue = (only ? [only] : services).filter(
        (service) => service.state === "missing" || service.state === "failed",
      );
      if (queue.length === 0) return;

      setBusy(true);
      setErrors((current) => {
        const next = { ...current };
        for (const service of queue) delete next[service.key];
        return next;
      });

      // Sequential, so a refusal part-way stops the remaining requests.
      for (const service of queue) {
        try {
          await INSTALL[service.kind](service);
        } catch (error) {
          setErrors((current) => ({ ...current, [service.key]: apiMessage(error) }));
          toast.error(apiMessage(error));
          break;
        }
      }

      setBusy(false);
      refresh();
    },
    [services, refresh],
  );

  if (rows.length === 0) return null;

  return (
    <RequiredServicesPanel
      typeTitle={type?.title ?? type?.name ?? ""}
      services={services.map((service) => ({
        ...service,
        error: errors[service.key] ?? null,
      }))}
      busy={busy}
      onInstall={() => start()}
      onRetry={(service) => start(service)}
    />
  );
}
