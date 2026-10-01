"use client";

import { useState } from "react";
import { usePendingKeys } from "@/hooks/use-pending-keys";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowLeft, File as FileIcon, RotateCcw, Trash2, TriangleAlert, Undo2 } from "lucide-react";
import { emptyTrash, restoreTrashed } from "@/lib/api/files";
import { basename, dirname } from "@/lib/files/path-helpers";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from "@/components/ui/card";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { useRefresh } from "@/hooks/use-refresh";

/**
 * What is recoverable, and the two ways out of it.
 *
 * Grouped by batch (the API assigns one batch id per delete), the unit a person
 * recognises. Not a table: each row is one path and one button, and a bordered
 * block per batch works at every width.
 *
 * No "restore all": the API restores one `{batch, path}` per call against a
 * 30/min throttle. Emptying a batch is a single call, so that is offered.
 */
export function TrashPanel({
  appId,
  trash,
  totalSize,
  retentionDays,
  failed,
  status = null,
  failure = null,
  message = null,
  canManage,
  backHref,
}) {
  const t = useTranslations("applications.files.trash");
  const { pending: refreshing, refresh, refreshThen } = useRefresh();
  // Emptying runs in a dialog that stays open until done; per-row restores can
  // overlap, so they are tracked separately.
  const [pending, setPending] = useState(null);
  const restoring = usePendingKeys();
  // Removed from the list as soon as the API confirms, so Restore cannot be pressed
  // again before the refresh lands ("already exists").
  const [restored, setRestored] = useState(() => new Set());
  const [confirming, setConfirming] = useState(null);

  const manageReason = canManage ? null : t("noPermission");

  // Map keeps insertion order, so the API's newest-first order survives.
  const batches = new Map();
  for (const entry of trash.filter((e) => !restored.has(`${e.batch}:${e.path}`))) {
    if (!batches.has(entry.batch)) batches.set(entry.batch, []);
    batches.get(entry.batch).push(entry);
  }

  async function restore(entry) {
    const key = `${entry.batch}:${entry.path}`;
    if (restoring.isPending(key)) return;
    restoring.start(key);
    try {
      await restoreTrashed(appId, entry.batch, entry.path);
      setRestored((prev) => new Set(prev).add(key));
      toast.success(t("restored", { name: entry.path }));
      refresh();
    } catch (error) {
      // 422 is the non-overwrite rule: something is already back at that path. The
      // API's message says so.
      toast.error(apiMessage(error, t("restoreFailed")));
    } finally {
      restoring.finish(key);
    }
  }

  async function empty(batch) {
    setPending(batch ?? "all");
    try {
      await emptyTrash(appId, batch);
      const done = batch ? t("batchEmptied") : t("emptied");
      refreshThen(() => {
        toast.success(done);
        setConfirming(null);
      });
    } catch (error) {
      toast.error(apiMessage(error, t("emptyFailed")));
    } finally {
      setPending(null);
    }
  }

  return (
    <Card>
      {/* A card, like every other panel on this screen. */}
      <CardHeader>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-48 space-y-1">
            <CardTitle className="text-base font-semibold">{t("title")}</CardTitle>
            <CardDescription>{t("subtitle")}</CardDescription>
            {/* Deleting frees no disk until the trash is emptied, and batches are swept after
                the retention window; both come from the response. The window is per-install
                and must never be hardcoded. */}
            {trash.length > 0 && (totalSize || retentionDays) ? (
              <p className="text-sm text-muted-foreground">
                {totalSize ? (
                  <span className="font-medium text-foreground">
                    {t("holding", { size: totalSize })}
                  </span>
                ) : null}
                {totalSize && retentionDays ? " · " : null}
                {retentionDays ? t("retention", { days: retentionDays }) : null}
              </p>
            ) : null}
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <RefreshButton className="size-8" />
            <Button variant="outline" size="sm" asChild>
              <Link href={backHref} prefetch={false}>
                <ArrowLeft className="size-3.5" />
                {t("backToFiles")}
              </Link>
            </Button>
            {trash.length > 0 ? (
              <ReasonTooltip reason={manageReason}>
                {/* Outline, not solid: Restore is the common action, and two solid red buttons
                    compete. */}
                <Button
                  variant="destructive"
                  size="sm"
                  disabled={!canManage}
                  onClick={() => setConfirming({ batch: null })}
                >
                  <Trash2 className="size-3.5" />
                  {t("emptyAll")}
                </Button>
              </ReasonTooltip>
            ) : null}
          </div>
        </div>
      </CardHeader>

      <CardContent>

      {failed ? (
        <LoadFailed description={t("loadFailed")} status={status} failure={failure} message={message} />
      ) : trash.length === 0 ? (
        // An empty trash is normal, so this explains the feature rather than offering an
        // action.
        <EmptyState icon={Undo2} title={t("empty.title")} description={t("empty.description")} />
      ) : (
        <div className="space-y-3">
          {[...batches].map(([batch, entries]) => (
            <div key={batch} className="rounded-xl border">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-muted/40 px-4 py-2.5">
                {/* Labelled, or a bare timestamp reads as an id. */}
                <div className="flex min-w-48 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                  <span className="text-xs text-muted-foreground">{t("deletedAt")}</span>
                  <span className="text-sm font-medium tabular-nums">
                    {entries[0].deleted_at ?? batch}
                  </span>
                  <Badge variant="secondary" className="font-normal">
                    {t("itemCount", { count: entries.length })}
                  </Badge>
                </div>
                <ReasonTooltip reason={manageReason}>
                  <Button
                    variant="ghost"
                    size="sm"
                    className="text-destructive hover:bg-destructive/10 hover:text-destructive"
                    disabled={!canManage || pending === batch}
                    onClick={() => setConfirming({ batch, count: entries.length })}
                  >
                    <Trash2 className="size-3.5" />
                    {t("emptyBatch")}
                  </Button>
                </ReasonTooltip>
              </div>

              <ul className="divide-y">
                {entries.map((entry) => {
                  const busy = restoring.isPending(`${entry.batch}:${entry.path}`);
                  return (
                    <li
                      key={`${entry.batch}:${entry.path}`}
                      className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    >
                      {/* Name first, folder underneath; the folder says which file it was and where
                          Restore puts it back. */}
                      <div className="flex min-w-48 items-start gap-2.5">
                        <FileIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0">
                          <p className="flex flex-wrap items-baseline gap-x-2">
                            <span className="font-medium break-all">{basename(entry.path)}</span>
                            {/* Size helps decide which batch to empty. */}
                            {entry.size_human ? (
                              <span className="text-xs tabular-nums text-muted-foreground">
                                {entry.size_human}
                              </span>
                            ) : null}
                          </p>
                          <p className="font-mono text-xs break-all text-muted-foreground">
                            {dirname(entry.path) || t("siteRoot")}
                          </p>
                        </div>
                      </div>
                      <ReasonTooltip reason={manageReason}>
                        <Button
                          variant="outline"
                          size="sm"
                          disabled={!canManage || busy}
                          onClick={() => restore(entry)}
                        >
                          <RotateCcw className="size-3.5" />
                          {busy ? t("restoring") : t("restore")}
                        </Button>
                      </ReasonTooltip>
                    </li>
                  );
                })}
              </ul>
            </div>
          ))}
        </div>
      )}
      </CardContent>

      <ConfirmDialog
        open={confirming !== null}
        onOpenChange={(next) => !next && pending === null && !refreshing && setConfirming(null)}
        icon={TriangleAlert}
        tone="destructive"
        title={confirming?.batch ? t("confirmBatch.title", { count: confirming.count }) : t("confirmAll.title")}
        description={confirming?.batch ? t("confirmBatch.description") : t("confirmAll.description")}
        cancelLabel={t("cancel")}
        confirmLabel={pending !== null || refreshing ? t("emptying") : t("confirmSubmit")}
        pending={pending !== null || refreshing}
        onConfirm={() => empty(confirming?.batch ?? null)}
      />
    </Card>
  );
}
