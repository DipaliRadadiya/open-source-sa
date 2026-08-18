"use client";

import { useEffect, useState } from "react";
import { useTranslations } from "next-intl";
import { Cog, Plus, Wand2 } from "lucide-react";
import { listWorkers } from "@/lib/api/workers";
import { workersResponseSchema } from "@/lib/schemas/worker";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { EmptyState } from "@/components/data-table/empty-state";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { WorkerChecksAlert } from "@/components/applications/workers/worker-checks-alert";
import { WorkersTable } from "@/components/applications/workers/workers-table";
import { WorkersCards } from "@/components/applications/workers/workers-cards";
import { CreateWorkerDialog } from "@/components/applications/workers/create-worker-dialog";

// Status is read from systemd on every GET — nothing is cached server-side, so
// the "refresh" here is just re-fetching, same as the Services page.
const POLL_MS = 4000;

// Same tokens as WorkerStatusBadge's state colours, so the summary dots read
// as the same language rather than a second, unrelated colour system.
const STATE_DOT = {
  running: "bg-success",
  degraded: "bg-warning",
  stopped: "bg-muted-foreground/50",
};

export function WorkersPanel({ appId, initialWorkers, initialPresets, initialChecks, canManage }) {
  const t = useTranslations("applications.workers");
  const [workers, setWorkers] = useState(initialWorkers);
  const [presets, setPresets] = useState(initialPresets);
  const [checks, setChecks] = useState(initialChecks);
  const [busy, setBusy] = useState({});
  const [createOpen, setCreateOpen] = useState(false);
  const [seed, setSeed] = useState(undefined);

  // A fresh server render (create/edit/delete's router.refresh()) is newer
  // than anything the poll holds — without this, a mutation's own toast
  // fires instantly but the row only catches up on the next poll tick.
  const [renderedWith, setRenderedWith] = useState(initialWorkers);
  if (renderedWith !== initialWorkers) {
    setRenderedWith(initialWorkers);
    setWorkers(initialWorkers);
    setPresets(initialPresets);
    setChecks(initialChecks);
  }

  const setRowBusy = (id, action) => setBusy((prev) => ({ ...prev, [id]: action }));

  // start/stop/restart answer with the worker as systemd reports it *after* the
  // action, so the row can be corrected from the response itself. Previously
  // the answer was thrown away and the badge fell back to its old value until
  // the next poll — which is what made a start look like nothing had happened.
  // Replaced, not merged. The response is the whole resource, and merging keeps
  // whatever it omits — which showed up as a row carrying a fresh `state` of
  // "running" beside the previous `state_title` of "Stopped", so the badge
  // contradicted its own buttons.
  const applyWorker = (next) =>
    setWorkers((prev) => prev.map((w) => (w.id === next.id ? next : w)));

  function openCreate(presetKey) {
    setSeed(presetKey);
    setCreateOpen(true);
  }

  useEffect(() => {
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
        // Transient poll error — keep the last known state.
      }
    }

    const id = setInterval(tick, POLL_MS);
    document.addEventListener("visibilitychange", tick);
    return () => {
      active = false;
      clearInterval(id);
      document.removeEventListener("visibilitychange", tick);
    };
  }, [appId]);

  const addButton = (
    <ReasonTooltip reason={canManage ? null : t("noPermission")}>
      <Button disabled={!canManage} onClick={() => openCreate()}>
        <Plus className="size-4" />
        {t("addWorker")}
      </Button>
    </ReasonTooltip>
  );

  // Only worth the line once there's enough of a list to want a head-count —
  // with one or two workers it'd just repeat what's already on screen. Dots
  // reuse the same colour tokens as the state badges, so the strip is
  // scannable at a glance instead of needing to be read.
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

  return (
    <div className="space-y-4">
      <WorkerChecksAlert checks={checks} />

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
          {addButton}
        </div>
      </div>

      {workers.length === 0 ? (
        <EmptyState
          icon={Cog}
          title={t("empty.title")}
          description={t("empty.description")}
          action={
            <div className="flex flex-col items-center gap-4">
              {addButton}
              {canManage && presets.length > 0 ? (
                <div className="flex flex-col items-center gap-2">
                  <span className="text-xs text-muted-foreground">{t("empty.starters")}</span>
                  <div className="flex flex-wrap justify-center gap-2">
                    {presets.map((p) => (
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
      ) : (
        <>
          <div className="lg:hidden">
            <WorkersCards
              data={workers}
              appId={appId}
              presets={presets}
              canManage={canManage}
              busy={busy}
              setRowBusy={setRowBusy}
              onWorkerUpdated={applyWorker}
            />
          </div>
          <div className="hidden lg:block">
            <WorkersTable
              data={workers}
              appId={appId}
              presets={presets}
              canManage={canManage}
              busy={busy}
              setRowBusy={setRowBusy}
              onWorkerUpdated={applyWorker}
            />
          </div>
        </>
      )}

      {canManage ? (
        <CreateWorkerDialog
          open={createOpen}
          onOpenChange={setCreateOpen}
          appId={appId}
          presets={presets}
          workers={workers}
          seed={seed}
        />
      ) : null}
    </div>
  );
}
