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
import { SyncResults, SyncResultsPending } from "@/components/sync/sync-results";
import { SyncSummary } from "@/components/sync/sync-summary";
import { EmptyState } from "@/components/data-table/empty-state";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";

const PAGE_SIZE = 500;
const POLL_MS = 2000;
const SCAN_STOP_MS = 10 * 60 * 1000;

export function SyncPanel({ run: initialRun, items: initialItems, ignores: initialIgnores, canManage }) {
  const t = useTranslations("sync");
  const { refresh, pending: refreshing } = useRefresh();

  const [run, setRun] = useState(initialRun);
  const [items, setItems] = useState(initialItems ?? []);
  const [ignores, setIgnores] = useState(initialIgnores ?? []);
  const [starting, setStarting] = useState(false);
  const [adoptOpen, setAdoptOpen] = useState(false);
  const ignoring = usePendingKeys();
  const [stalled, setStalled] = useState(false);

  // A ref: the poll loop reads it between renders, and a stale closure would refetch forever.
  const cursor = useRef(initialItems?.length ? initialItems[initialItems.length - 1].id : 0);

  const runId = run?.id ?? null;
  const ignoredKeys = ignoreKeySet(ignores);

  useEffect(() => {
    if (!runId) return undefined;

    let cancelled = false;
    let timer = null;
    const wait = (ms) =>
      new Promise((resolve) => {
        timer = setTimeout(resolve, ms);
      });

    (async () => {
      // A run whose worker died never finishes, and a deleted run 404s forever.
      const startedAt = Date.now();

      while (!cancelled) {
        if (Date.now() - startedAt > SCAN_STOP_MS) {
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
              setItems((current) => [...current, ...batch]);
            }

            if (!cancelled) setRun(next);

            if (next.finished && batch.length < PAGE_SIZE) return;
          }
        } catch {
          // retry on the next poll
        }

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
        // A scan always reads the firewall; only adopting it is opt-in.
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
      toast.error(apiMessage(error, mode === "apply" ? t("errors.addFailed") : t("errors.startFailed")));
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

  const actions = (
    <div className="flex flex-wrap items-center gap-2">
      {canManage ? (
        <Button onClick={() => begin("preview")} disabled={starting || running} className="max-sm:w-full">
          {starting || running ? (
            <Loader2 className="size-4 animate-spin" aria-hidden />
          ) : (
            <ScanSearch className="size-4" aria-hidden />
          )}
          {run ? t("actions.rescan") : t("actions.scan")}
        </Button>
      ) : null}

      {canManage && run?.finished && items.some((item) => item.action === "found") ? (
        <Button variant="secondary" onClick={() => setAdoptOpen(true)} className="max-sm:w-full">
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
        <Button variant="outline" onClick={refresh} disabled={refreshing}>
          <RefreshCw className={cn("size-4", refreshing && "animate-spin")} aria-hidden />
          {t("actions.refresh")}
        </Button>
      ) : null}
    </div>
  );

  return (
    <div className="space-y-6">
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
          loaded={running ? items.length : items.filter((item) => !ignoredKeys.has(ignoreKey(item))).length}
          addable={items.filter((item) => item.action === "found" && !ignoredKeys.has(ignoreKey(item))).length}
          running={running}
          actions={actions}
        />
      ) : null}

      {!run ? (
        <EmptyState
          icon={ScanSearch}
          badge={null}
          title={t("empty.title")}
          description={t("empty.description")}
          action={actions}
        />
      ) : items.length === 0 && running ? (
        <SyncResultsPending
          message={run.mode === "preview" ? t("results.waiting") : t("results.waitingAdopt")}
        />
      ) : items.length === 0 && run.finished ? (
        run.status === "failed" ? null : (
          <EmptyState
            icon={ScanSearch}
            title={t("empty.nothingFound")}
            description={t("empty.nothingFoundHint")}
          />
        )
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
