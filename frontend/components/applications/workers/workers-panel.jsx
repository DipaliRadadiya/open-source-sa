"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Cog, Plus, Wand2 } from "lucide-react";
import { listWorkers } from "@/lib/api/workers";
import { workersResponseSchema } from "@/lib/schemas/worker";
import { Button } from "@/components/ui/button";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ListCard } from "@/components/data-table/list-card";
import { EmptyState } from "@/components/data-table/empty-state";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { WorkerChecksAlert } from "@/components/applications/workers/worker-checks-alert";
import { SupervisorMissingAlert } from "@/components/applications/workers/supervisor-missing-alert";
import { WorkersTable } from "@/components/applications/workers/workers-table";
import { WorkersCards } from "@/components/applications/workers/workers-cards";
import { CreateWorkerDialog } from "@/components/applications/workers/create-worker-dialog";
import { WorkerSiteProvider } from "@/components/applications/workers/worker-site-context";

const POLL_MS = 15000;

const STATE_DOT = {
  running: "bg-success",
  degraded: "bg-warning",
  stopped: "bg-muted-foreground/50",
};

export function WorkersPanel({ appId, initialWorkers, initialPresets, initialChecks, supervisorMissing = false, canManage, siteUser = null, appRoot = "", canViewLogs = false }) {
  const t = useTranslations("applications.workers");
  const [workers, setWorkers] = useState(initialWorkers);
  const [presets, setPresets] = useState(initialPresets);
  const [checks, setChecks] = useState(initialChecks);
  const [busy, setBusy] = useState({});
  const [createOpen, setCreateOpen] = useState(false);
  const [seed, setSeed] = useState(undefined);

  const [renderedWith, setRenderedWith] = useState(initialWorkers);
  if (renderedWith !== initialWorkers) {
    setRenderedWith(initialWorkers);
    setWorkers(initialWorkers);
    setPresets(initialPresets);
    setChecks(initialChecks);
  }

  const setRowBusy = (id, action) => setBusy((prev) => ({ ...prev, [id]: action }));

  // Replace, don't merge, or a stale state_title stays beside the new state.
  const applyWorker = (next) =>
    setWorkers((prev) => prev.map((w) => (w.id === next.id ? next : w)));

  function openCreate(presetKey) {
    setSeed(presetKey);
    setCreateOpen(true);
  }

  // Paused while an action runs so a poll can't overwrite its newer response.
  const anyBusy = Object.values(busy).some(Boolean);

  useEffect(() => {
    if (anyBusy) return undefined;
    let active = true;

    async function tick() {
      if (document.hidden) return;
      try {
        const { data } = await listWorkers(appId);
        const parsed = workersResponseSchema.safeParse(data);
        if (!active || !parsed.success) return;
        setWorkers(parsed.data.workers);
        setPresets(parsed.data.presets);
        setChecks(parsed.data.checks);
      } catch {
        // retry on the next poll
      }
    }

    const id = setInterval(tick, POLL_MS);
    document.addEventListener("visibilitychange", tick);
    return () => {
      active = false;
      clearInterval(id);
      document.removeEventListener("visibilitychange", tick);
    };
  }, [appId, anyBusy]);

  const addButton = (
    <ReasonTooltip reason={canManage ? null : DENIED}>
      <Button disabled={!canManage} onClick={() => openCreate()}>
        <Plus className="size-4" />
        {t("addWorker")}
      </Button>
    </ReasonTooltip>
  );

  let summaryParts = null;
  if (workers.length > 2) {
    const counts = workers.reduce((acc, w) => {
      acc[w.state] = (acc[w.state] ?? 0) + 1;
      return acc;
    }, {});
    summaryParts = ["running", "degraded", "stopped"]
      .filter((state) => counts[state])
      .map((state) => ({ state, count: counts[state] }));
  }

  const starters = presets.filter((p) => !(p.kind === "custom" && !p.command));

  const toolbar = (
    <div className="flex items-center justify-between gap-3">
      {summaryParts ? (
        <div className="flex items-center gap-3">
          {summaryParts.map(({ state, count }) => (
            <span key={state} className="flex items-center gap-1.5 text-xs text-muted-foreground">
              <span className={`size-1.5 shrink-0 rounded-full ${STATE_DOT[state]}`} />
              {count} {t(`state.${state}`)}
            </span>
          ))}
        </div>
      ) : (
        <span />
      )}
      <div className="flex flex-wrap items-center gap-2">
        <RefreshButton />
        {workers.length ? addButton : null}
      </div>
    </div>
  );

  return (
    <WorkerSiteProvider value={{ appRoot }}>
    <div className="space-y-4">
      {supervisorMissing ? (
        <SupervisorMissingAlert appId={appId} canManage={canManage} />
      ) : null}

      <WorkerChecksAlert checks={checks} />

      {workers.length === 0 ? (
        <ListCard>
          <EmptyState
            icon={Cog}
            title={t("empty.title")}
            description={t(
              presets.some((p) => p.kind === "queue" || p.kind === "horizon")
                ? "empty.description"
                : "empty.descriptionGeneric",
            )}
            action={
              <div className="flex flex-col items-center gap-4">
                {addButton}
                {canManage && starters.length > 0 ? (
                  <div className="flex flex-col items-center gap-2">
                    <span className="text-xs text-muted-foreground">{t("empty.starters")}</span>
                    <div className="flex flex-wrap justify-center gap-2">
                      {starters.map((p) => (
                        <Button
                          key={p.key}
                          variant="outline"
                          size="sm"
                          onClick={() => openCreate(p.key)}
                        >
                          <Wand2 className="size-3.5" />
                          {p.title}
                        </Button>
                      ))}
                    </div>
                  </div>
                ) : null}
              </div>
            }
          />
        </ListCard>
      ) : (
        <ListCard from="c900" toolbar={toolbar}>
          <div className="@min-[900px]/page:hidden">
            <WorkersCards
              data={workers}
              appId={appId}
              presets={presets}
              canManage={canManage}
              canViewLogs={canViewLogs}
              busy={busy}
              setRowBusy={setRowBusy}
              onWorkerUpdated={applyWorker}
            />
          </div>
          <div className="hidden @min-[900px]/page:block">
            <WorkersTable
              data={workers}
              appId={appId}
              presets={presets}
              canManage={canManage}
              canViewLogs={canViewLogs}
              busy={busy}
              setRowBusy={setRowBusy}
              onWorkerUpdated={applyWorker}
            />
          </div>
        </ListCard>
      )}

      {canManage ? (
        <CreateWorkerDialog
          open={createOpen}
          onOpenChange={setCreateOpen}
          appId={appId}
          presets={presets}
          workers={workers}
          seed={seed}
          siteUser={siteUser}
        />
      ) : null}
    </div>
    </WorkerSiteProvider>
  );
}
