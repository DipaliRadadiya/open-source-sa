"use client";

import { useEffect, useRef, useState } from "react";
import { usePendingKeys } from "@/hooks/use-pending-keys";
import { DownloadCloud, Loader2, RefreshCw, ScanSearch } from "lucide-react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  getSyncRun,
  ignoreSyncItem,
  startSync,
  unignoreSyncItem,
} from "@/lib/api/sync";
import { cn } from "@/lib/utils";
import { apiMessage } from "@/lib/api/error-message";
import { syncRunResponseSchema } from "@/lib/schemas/sync";
import { ignoreKey, ignoreKeySet, typesPresent } from "@/lib/server/sync-selection";
import { AdoptDialog } from "@/components/sync/adopt-dialog";
import { IgnoredSheet } from "@/components/sync/ignored-sheet";
import { SyncResults } from "@/components/sync/sync-results";
import { SyncSummary } from "@/components/sync/sync-summary";
import { EmptyState } from "@/components/data-table/empty-state";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";

/* One page of items per call, matching the backend's limit. A full page means
   more is waiting, so the next poll goes out immediately. */
const PAGE_SIZE = 500;
const POLL_MS = 2000;
/* A scan walks the disk; ten minutes is generous for the largest server and
   still short enough that a dead worker is noticed in the same session. */
const SCAN_STOP_MS = 10 * 60 * 1000;

export function SyncPanel({ run: initialRun, items: initialItems, ignores: initialIgnores, canManage }) {
  const t = useTranslations("sync");
  const { refresh, pending: refreshing } = useRefresh();

  const [run, setRun] = useState(initialRun);
  const [items, setItems] = useState(initialItems ?? []);
  const [ignores, setIgnores] = useState(initialIgnores ?? []);
  const [starting, setStarting] = useState(false);
  const [adoptOpen, setAdoptOpen] = useState(false);
  // Ignoring or restoring several rows at once, each with its own spinner.
  const ignoring = usePendingKeys();
  // Set when the poll gives up: the run never reported finishing.
  const [stalled, setStalled] = useState(false);

  /* A ref, not state: the poll loop reads it between renders, and a stale
     closure would re-request the same rows forever. */
  const cursor = useRef(initialItems?.length ? initialItems[initialItems.length - 1].id : 0);

  const runId = run?.id ?? null;
  const ignoredKeys = ignoreKeySet(ignores);

  /* Keyed on the run id alone: depending on `finished` would stop the loop
     while a full final page is still unread. The loop decides when it is done. */
  useEffect(() => {
    if (!runId) return undefined;

    let cancelled = false;
    let timer = null;
    const wait = (ms) =>
      new Promise((resolve) => {
        timer = setTimeout(resolve, ms);
      });

    (async () => {
      // When this loop started, so it can stop: a run whose worker died never
      // sets `finished`, and a deleted run 404s forever.
      const startedAt = Date.now();

      while (!cancelled) {
        if (Date.now() - startedAt > SCAN_STOP_MS) {
          // Say so rather than leaving a progress bar that silently stopped.
          if (!cancelled) setStalled(true);
          return;
        }

        let batchLength = 0;

        try {
          const { data } = await getSyncRun(runId, { since: cursor.current });
          const parsed = syncRunResponseSchema.safeParse(data);

          if (parsed.success && parsed.data.sync) {
            const next = parsed.data.sync;
            const batch = next.items ?? [];
            batchLength = batch.length;

            if (batch.length) {
              cursor.current = batch[batch.length - 1].id;
              /* Append: the feed is cursor-based, so each poll carries only
                 new rows. */
              setItems((current) => [...current, ...batch]);
            }

            if (!cancelled) setRun(next);

            // Done only when the run has ended AND the feed has run dry.
            if (next.finished && batch.length < PAGE_SIZE) return;
          }
        } catch {
          // A dropped poll is not a failed run; wait and ask again.
        }

        // A full page means more is certainly waiting, so don't idle for it.
        if (batchLength !== PAGE_SIZE) await wait(POLL_MS);
      }
    })();

    return () => {
      cancelled = true;
      if (timer) clearTimeout(timer);
    };
  }, [runId]);

  async function begin(mode, options = {}) {
    setStarting(true);
    try {
      const { data } = await startSync({
        /*
         * A scan always reads the firewall (`ufw status numbered`, read-only);
         * only ADOPTING it is opt-in. Otherwise no firewall items appear and
         * the opt-in checkbox, which renders only when they exist, is
         * unreachable. `apply` passes its own value, and the spread below lets
         * it win.
         */
        includeFirewall: mode === "preview",
        mode,
        ...options,
      });
      const parsed = syncRunResponseSchema.safeParse(data);
      if (!parsed.success || !parsed.data.sync) throw new Error("shape");

      cursor.current = 0;
      setItems([]);
      setStalled(false);
      setRun(parsed.data.sync);
      setAdoptOpen(false);
    } catch (error) {
      // A second live run is a 422 whose message explains it; show it as is.
      toast.error(apiMessage(error, t("errors.startFailed")));
    } finally {
      setStarting(false);
    }
  }

  async function onIgnore(item) {
    const key = ignoreKey(item);
    if (ignoring.isPending(key)) return;
    ignoring.start(key);
    try {
      const { data } = await ignoreSyncItem({
        resourceType: item.resource_type,
        resourceKey: item.resource_key,
      });
      setIgnores((current) => [
        {
          id: data?.ignore?.id ?? Date.now(),
          resource_type: item.resource_type,
          resource_key: item.resource_key,
        },
        ...current,
      ]);
    } catch (error) {
      toast.error(apiMessage(error, t("errors.ignoreFailed")));
    } finally {
      ignoring.finish(key);
    }
  }

  async function onUnignore(target) {
    const key = ignoreKey(target);
    const entry = ignores.find((ignore) => ignoreKey(ignore) === key);
    if (!entry) return;

    if (ignoring.isPending(key)) return;
    ignoring.start(key);
    try {
      await unignoreSyncItem(entry.id);
      setIgnores((current) => current.filter((ignore) => ignore.id !== entry.id));
    } catch (error) {
      toast.error(apiMessage(error, t("errors.unignoreFailed")));
    } finally {
      ignoring.finish(key);
    }
  }

  const running = Boolean(run && !run.finished);
  const present = typesPresent(items);

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-2">
        {canManage ? (
          <Button onClick={() => begin("preview")} disabled={starting || running}>
            {starting || running ? (
              <Loader2 className="size-4 animate-spin" aria-hidden />
            ) : (
              <ScanSearch className="size-4" aria-hidden />
            )}
            {run ? t("actions.rescan") : t("actions.scan")}
          </Button>
        ) : null}

        {canManage && run?.finished && items.some((item) => item.action === "found") ? (
          <Button variant="secondary" onClick={() => setAdoptOpen(true)}>
            <DownloadCloud className="size-4" aria-hidden />
            {t("actions.adopt")}
          </Button>
        ) : null}

        <IgnoredSheet
          ignores={ignores}
          canManage={canManage}
          pendingKeys={ignoring.pendingKeys}
          onUnignore={onUnignore}
        />

        {run?.finished ? (
          <Button variant="ghost" onClick={refresh} disabled={refreshing}>
            <RefreshCw className={cn("size-4", refreshing && "animate-spin")} aria-hidden />
            {t("actions.refresh")}
          </Button>
        ) : null}
      </div>

      {/* Above the summary, whose counts are what stopped being true. */}
      {stalled ? (
        <p className="flex flex-wrap items-center gap-x-2 gap-y-1 rounded-lg border border-warning/40 bg-warning/10 px-3 py-2.5 text-sm text-warning">
          {t("stalled")}
          <Button
            variant="link"
            size="sm"
            className="h-auto p-0 text-sm text-warning"
            onClick={() => begin("preview")}
            disabled={starting}
          >
            {starting ? <Loader2 className="size-3.5 animate-spin" /> : null}
            <RefreshCw className="size-3.5" aria-hidden />
            {t("actions.rescan")}
          </Button>
        </p>
      ) : null}

      {run ? (
        <SyncSummary
          run={run}
          // Dismissed rows stay in the list (with Undo) but are not counted.
          loaded={running ? items.length : items.filter((item) => !ignoredKeys.has(ignoreKey(item))).length}
          running={running}
        />
      ) : null}

      {!run ? (
        <EmptyState
          icon={ScanSearch}
          title={t("empty.title")}
          description={t("empty.description")}
        />
      ) : items.length === 0 && run.finished ? (
        <EmptyState
          icon={ScanSearch}
          title={t("empty.nothingFound")}
          description={t("empty.nothingFoundHint")}
        />
      ) : (
        <SyncResults
          items={items}
          ignoredKeys={ignoredKeys}
          canManage={canManage}
          onIgnore={onIgnore}
          onUnignore={onUnignore}
          pendingKeys={ignoring.pendingKeys}
        />
      )}

      {/* Keyed on the run so a new scan gets fresh type checkboxes. */}
      <AdoptDialog
        key={run?.id ?? "none"}
        open={adoptOpen}
        onOpenChange={setAdoptOpen}
        items={items}
        ignoredKeys={ignoredKeys}
        typesPresent={present}
        pending={starting}
        onConfirm={({ only, includeFirewall }) =>
          begin("apply", { only, includeFirewall })
        }
      />
    </div>
  );
}
