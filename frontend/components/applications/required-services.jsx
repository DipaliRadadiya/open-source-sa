"use client";

import { useCallback, useEffect, useMemo, useState } from "react";
import { toast } from "sonner";

import { useRefresh } from "@/hooks/use-refresh";
import { installEngine } from "@/lib/api/databases";
import { installPhpVersion } from "@/lib/api/php";
import { installNodeVersion } from "@/lib/api/node";
import { apiMessage } from "@/lib/api/error-message";
import { highestInRange } from "@/lib/runtime/version-range";
import { RequiredServicesPanel } from "@/components/applications/required-services-panel";

/**
 * Install what the chosen application needs, from the form that needs it.
 *
 * The user's words: "detecting all the required dependencies upfront … the
 * installer could list the required services and ask for permission to install
 * them all at once, rather than requiring the user to go back and forth
 * between different sections and discover dependencies one at a time."
 *
 * No new endpoint. `POST /databases/engines/{engine}`, `POST /php/versions`
 * and `POST /node/versions` are each already a 202 and a queued job, already
 * idempotent, and the queue runs a single worker — so firing all of them is
 * safe and they simply run in turn.
 *
 * NO local state machine. Every row's state is read from the same live data
 * the rest of the page renders from: an engine's `installed`/`running`, a
 * version's `status`. A second copy of "is it installed yet" kept in this
 * component is a copy that can disagree with the page around it.
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
 *
 * Computed ONCE, when an application is chosen. The blockers vanish as they
 * are cleared — that is the point of them — and a list derived from them on
 * every render would delete the row that just went green before anyone saw it.
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
     * WHICH version, decided here rather than left to the reader.
     *
     * `suggest` is set when a range excluded everything installed. When the
     * server has none of this runtime at all there is no range to satisfy and
     * no `suggest`, so the newest version on offer is the answer — the same
     * one the runtime's own page would put at the top of its list.
     *
     * Null means nothing we can install fits, which is a state of its own:
     * PrestaShop wants PHP 7.2–8.1 and this panel only offers 8.3 and 8.4.
     * Offering a button there would be a button that cannot work.
     */
    const version = blocker.suggest ?? highestInRange(installable[runtime], blocker.range ?? null);
    const label = RUNTIME_NAMES[runtime] ?? runtime;

    return [
      {
        key: `runtime-${runtime}`,
        kind: runtime,
        version,
        // Never the bare runtime name: "PHP — Not installed" is false on a
        // server running PHP 8.4. It is the wrong LINE, not absent.
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
      rows.map((row) => ({
        ...row,
        state: liveState(row, { engines, phpVersions, nodeVersions, canInstall, errors }),
      })),
    [rows, engines, phpVersions, nodeVersions, canInstall, errors],
  );

  const working = services.some((service) => service.state === "installing");

  /*
   * Poll only while something is running, and by re-running the page rather
   * than fetching here. The versions, the engines and the type's own blockers
   * are all computed server-side from one another — refreshing moves them
   * together, and a client fetch into local state would update this panel and
   * leave the card grid above it still saying the application cannot be made.
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

      // Sequentially, though each call only enqueues: a failure part-way
      // through should stop asking for the rest rather than firing them at a
      // server that has just refused something.
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
