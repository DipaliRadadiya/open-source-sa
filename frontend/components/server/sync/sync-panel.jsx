"use client";

import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { DownloadCloud, Loader2, RefreshCw, ScanSearch } from "lucide-react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  getSyncRun,
  ignoreSyncItem,
  startSync,
  unignoreSyncItem,
} from "@/lib/api/sync";
import { apiMessage } from "@/lib/api/error-message";
import { syncRunResponseSchema } from "@/lib/schemas/sync";
import { ignoreKey, ignoreKeySet, typesPresent } from "@/lib/server/sync-selection";
import { AdoptDialog } from "@/components/server/sync/adopt-dialog";
import { IgnoredSheet } from "@/components/server/sync/ignored-sheet";
import { SyncResults } from "@/components/server/sync/sync-results";
import { SyncSummary } from "@/components/server/sync/sync-summary";
import { EmptyState } from "@/components/data-table/empty-state";
import { Button } from "@/components/ui/button";

/* One page of items per call, matching the backend's limit. A full page means
   there is certainly more behind it, so the next poll goes out immediately
   instead of waiting — otherwise draining a box with 1,200 rows would take
   forty seconds of deliberate idling. */
const PAGE_SIZE = 500;
const POLL_MS = 2000;

export function SyncPanel({ run: initialRun, items: initialItems, ignores: initialIgnores, canManage }) {
  const t = useTranslations("sync");
  const router = useRouter();

  const [run, setRun] = useState(initialRun);
  const [items, setItems] = useState(initialItems ?? []);
  const [ignores, setIgnores] = useState(initialIgnores ?? []);
  const [starting, setStarting] = useState(false);
  const [adoptOpen, setAdoptOpen] = useState(false);
  const [pendingKey, setPendingKey] = useState(null);

  /* The cursor is a ref, not state: the poll loop reads it between renders and
     a stale closure over a state value would re-request from the same id
     forever, appending the same rows on every tick. */
  const cursor = useRef(initialItems?.length ? initialItems[initialItems.length - 1].id : 0);

  const runId = run?.id ?? null;
  const ignoredKeys = ignoreKeySet(ignores);

  /* Keyed on the run id alone, deliberately. Depending on `finished` too would
     tear the loop down the moment the run completed — and a run that finishes
     holding a full page still has rows this screen has never seen. The loop
     decides when it is done, not the dependency array. */
  useEffect(() => {
    if (!runId) return undefined;

    let cancelled = false;
    let timer = null;
    const wait = (ms) =>
      new Promise((resolve) => {
        timer = setTimeout(resolve, ms);
      });

    (async () => {
      while (!cancelled) {
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
              /* Append. The feed is cursor-based precisely so a poll carries
                 only what is new; replacing the list would throw away
                 everything before the cursor and the table would shrink as the
                 run went on. */
              setItems((current) => [...current, ...batch]);
            }

            if (!cancelled) setRun(next);

            // Done only when the run has ended AND the feed has run dry.
            if (next.finished && batch.length < PAGE_SIZE) return;
          }
        } catch {
          // A dropped poll is not a failed run. Wait and ask again rather than
          // tearing the screen down over one bad response.
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
      const { data } = await startSync({ mode, ...options });
      const parsed = syncRunResponseSchema.safeParse(data);
      if (!parsed.success || !parsed.data.sync) throw new Error("shape");

      cursor.current = 0;
      setItems([]);
      setRun(parsed.data.sync);
      setAdoptOpen(false);
    } catch (error) {
      // A second run while one is live is a 422 carrying the backend's own
      // sentence — showing ours instead would say "something went wrong" over
      // a message that already explains exactly what happened.
      toast.error(apiMessage(error, t("errors.startFailed")));
    } finally {
      setStarting(false);
    }
  }

  async function onIgnore(item) {
    const key = ignoreKey(item);
    setPendingKey(key);
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
      setPendingKey(null);
    }
  }

  async function onUnignore(target) {
    const key = ignoreKey(target);
    const entry = ignores.find((ignore) => ignoreKey(ignore) === key);
    if (!entry) return;

    setPendingKey(key);
    try {
      await unignoreSyncItem(entry.id);
      setIgnores((current) => current.filter((ignore) => ignore.id !== entry.id));
    } catch (error) {
      toast.error(apiMessage(error, t("errors.unignoreFailed")));
    } finally {
      setPendingKey(null);
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
          pendingKey={pendingKey}
          onUnignore={onUnignore}
        />

        {run?.finished ? (
          <Button variant="ghost" onClick={() => router.refresh()}>
            <RefreshCw className="size-4" aria-hidden />
            {t("actions.refresh")}
          </Button>
        ) : null}
      </div>

      {run ? (
        <SyncSummary run={run} loaded={items.length} running={running} />
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
          pendingKey={pendingKey}
        />
      )}

      {/* Keyed on the run so a second scan gets a dialog with fresh type
          checkboxes. Mounted once, its selection would still describe the
          previous run's types — and this dialog's whole job is to state
          accurately what is about to be written. */}
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
