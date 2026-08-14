"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
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
    <Card>
      {/* A card, like every other panel on this screen. This section used to
          float straight on the page background under the page's own title, so
          the screen read as two stacked headings and a loose list. */}
      <CardHeader>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div className="min-w-48 space-y-1">
            <CardTitle className="text-base font-semibold">{t("title")}</CardTitle>
            <CardDescription>{t("subtitle")}</CardDescription>
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
                {/* Outline, not solid. Two solid red buttons on one screen —
                    this and the per-batch one — competed for the eye, and the
                    one you want most of the time is Restore. */}
                <Button
                  variant="outline"
                  size="sm"
                  className="border-destructive/30 text-destructive hover:bg-destructive/10 hover:text-destructive"
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
        <LoadFailed description={t("loadFailed")} />
      ) : trash.length === 0 ? (
        // An empty trash is a normal answer, not a problem to solve — so this
        // says what the feature does rather than offering an action.
        <EmptyState icon={Undo2} title={t("empty.title")} description={t("empty.description")} />
      ) : (
        <div className="space-y-3">
          {[...batches].map(([batch, entries]) => (
            <div key={batch} className="rounded-xl border">
              <div className="flex flex-wrap items-center justify-between gap-2 border-b bg-muted/40 px-4 py-2.5">
                {/* Labelled. A bare `14-08-2026 07:05:53` sitting where a title
                    goes reads as an id, not as when this happened. */}
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
                  const busy = pending === `${entry.batch}:${entry.path}`;
                  return (
                    <li
                      key={`${entry.batch}:${entry.path}`}
                      className="flex flex-wrap items-center justify-between gap-3 px-4 py-3"
                    >
                      {/* Name first, folder underneath. The row used to be one
                          long mono path and a button an inch of empty space
                          away — the thing you are looking for was the hardest
                          part to read. The folder still has to be there:
                          "config.php" alone does not say which one it was, and
                          it is exactly where Restore puts it back. */}
                      <div className="flex min-w-48 items-start gap-2.5">
                        <FileIcon className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
                        <div className="min-w-0">
                          <p className="font-medium break-all">{basename(entry.path)}</p>
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
    </Card>
  );
}
