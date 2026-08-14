"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ArrowLeft, RotateCcw, Trash2, TriangleAlert, Undo2 } from "lucide-react";
import { emptyTrash, restoreTrashed } from "@/lib/api/files";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { EmptyState } from "@/components/data-table/empty-state";
import { LoadFailed } from "@/components/data-table/load-failed";

/**
 * What is recoverable, and the two ways out of it.
 *
 * Grouped by batch because that is the unit a person recognises — "the twelve
 * things I deleted at 10:45", not twelve unrelated rows. The API assigns one
 * batch id per delete for exactly this reason.
 *
 * Not a table: every row is one path and one button, and a table here would
 * duplicate itself into a card list below `md` for no gain. A bordered block
 * per batch reads the same at 390px as at 1440px.
 *
 * **Restore is per row and there is no "restore all".** The API takes one
 * `{batch, path}` per call, so restoring a batch of twelve would be twelve
 * requests against a 30/min throttle. Emptying one batch IS a single call, so
 * that one is offered. See memory/backend-asks.md.
 */
export function TrashPanel({ appId, trash, failed, canManage, backHref }) {
  const t = useTranslations("applications.files.trash");
  const router = useRouter();
  const [pending, setPending] = useState(null);
  const [confirming, setConfirming] = useState(null);

  const manageReason = canManage ? null : t("noPermission");

  // Preserves the order the API sends (newest first) — Map keeps insertion
  // order, so the newest batch stays at the top without a second sort.
  const batches = new Map();
  for (const entry of trash) {
    if (!batches.has(entry.batch)) batches.set(entry.batch, []);
    batches.get(entry.batch).push(entry);
  }

  async function restore(entry) {
    setPending(`${entry.batch}:${entry.path}`);
    try {
      await restoreTrashed(appId, entry.batch, entry.path);
      toast.success(t("restored", { name: entry.path }));
      router.refresh();
    } catch (error) {
      // 422 here is the non-overwrite rule: something is back at that path and
      // the file sitting there now is the one somebody kept. Worth saying in
      // full rather than as "couldn't restore".
      toast.error(apiMessage(error, t("restoreFailed")));
    } finally {
      setPending(null);
    }
  }

  async function empty(batch) {
    setPending(batch ?? "all");
    try {
      await emptyTrash(appId, batch);
      toast.success(batch ? t("batchEmptied") : t("emptied"));
      setConfirming(null);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("emptyFailed")));
    } finally {
      setPending(null);
    }
  }

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="min-w-48 space-y-1">
          <h2 className="text-base font-medium">{t("title")}</h2>
          <p className="text-sm text-muted-foreground">{t("subtitle")}</p>
        </div>
        <div className="flex flex-wrap items-center gap-2">
          <Button variant="outline" size="sm" asChild>
            <Link href={backHref} prefetch={false}>
              <ArrowLeft className="size-3.5" />
              {t("backToFiles")}
            </Link>
          </Button>
          {trash.length > 0 ? (
            <ReasonTooltip reason={manageReason}>
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

      {failed ? (
        <LoadFailed description={t("loadFailed")} />
      ) : trash.length === 0 ? (
        // An empty trash is a normal answer, not a problem to solve — so this
        // says what the feature does rather than offering an action.
        <EmptyState icon={Undo2} title={t("empty.title")} description={t("empty.description")} />
      ) : (
        <div className="space-y-3">
          {[...batches].map(([batch, entries]) => (
            <div key={batch} className="rounded-xl border">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-muted/40 px-3 py-2">
                <div className="flex min-w-48 flex-wrap items-baseline gap-x-2 gap-y-0.5">
                  <span className="text-sm font-medium">
                    {entries[0].deleted_at ?? batch}
                  </span>
                  <span className="text-xs text-muted-foreground">
                    {t("itemCount", { count: entries.length })}
                  </span>
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
                  const busy = pending === `${entry.batch}:${entry.path}`;
                  return (
                    <li
                      key={`${entry.batch}:${entry.path}`}
                      className="flex flex-wrap items-center justify-between gap-2 px-3 py-2"
                    >
                      {/* The full original path, not just the name: "config.php"
                          alone does not say which one it was, and the path is
                          also exactly where it goes back to. */}
                      <span className="min-w-48 font-mono text-xs break-all">{entry.path}</span>
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

      <ConfirmDialog
        open={confirming !== null}
        onOpenChange={(next) => !next && pending === null && setConfirming(null)}
        icon={TriangleAlert}
        tone="destructive"
        title={confirming?.batch ? t("confirmBatch.title", { count: confirming.count }) : t("confirmAll.title")}
        description={confirming?.batch ? t("confirmBatch.description") : t("confirmAll.description")}
        cancelLabel={t("cancel")}
        confirmLabel={pending !== null ? t("emptying") : t("confirmSubmit")}
        pending={pending !== null}
        onConfirm={() => empty(confirming?.batch ?? null)}
      />
    </div>
  );
}
