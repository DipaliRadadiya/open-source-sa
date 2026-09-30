import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { CircleAlert, History, RotateCw, Trash2 } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { apiDuration } from "@/lib/format/api-date";
import { reasonText } from "@/lib/backups/reason";
import { isBackupStale } from "@/lib/backups/stale";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
import { Button } from "@/components/ui/button";
import { ActionIcon } from "@/components/ui/action-icon";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { BackupStatusBadge, SafetyBadge } from "@/components/backups/backup-status-badge";
import { DownloadBackupButton } from "@/components/backups/download-backup-button";
import { restoreBlocker } from "@/components/backups/restore-dialog";
import { sizeNote } from "@/components/backups/backups-history-table";
import { DeleteBackupsDialog } from "@/components/backups/delete-backups-dialog";

/**
 * Backup rows on a phone, where a five-column table cannot go.
 *
 * Shared by the History screen and the application page — the same component
 * the desktop table pairs with, so a backup describes itself identically
 * wherever you meet it. `showSite` is the only difference: on a site's own
 * page, naming the site in every row is noise.
 */
export function BackupsCards({
  backups,
  canRestore,
  canRun,
  onRestore,
  onRetry,
  onClear,
  busyId,
  retryBlockedFor = null,
  canClear = false,
  showSite = true,
  restoreInFlight = false,
  // The desktop table deletes through row selection, which a card has no room
  // for — so phones had no way to delete a backup at all. One button per card
  // opens the same dialog with that one backup.
  canDelete = false,
  onDeleted,
}) {
  const t = useTranslations("backups.history");
  const tr = useTranslations("backups.restore");
  const format = useFormatter();
  const [deleting, setDeleting] = useState(null);

  return (
    <>
    <CardList>
      {backups.map((backup) => {
        const duration = apiDuration(backup.started_at, backup.finished_at);
        const blocker = canRestore
          ? restoreBlocker(backup, tr, restoreInFlight)
          : t("noPermission");

        return (
          <CardListItem key={backup.id}>
            <div className="flex items-start justify-between gap-3">
              {showSite ? (
                <div className="min-w-0">
                  <p className="truncate font-medium">
                    {backup.application_name ?? t("unknownApplication")}
                  </p>
                  <p className="truncate text-xs text-muted-foreground">
                    {backup.application_domain ?? ""}
                  </p>
                </div>
              ) : (
                // Wraps rather than truncating: "Dateien und Datenbank" lost its
                // last word at 390px, and the type is the card's title.
                <p className="min-w-0 break-words font-medium">
                  {backup.type_title ?? backup.type}
                </p>
              )}
              <div className="flex shrink-0 flex-col items-end gap-1">
                <BackupStatusBadge backup={backup} />
                {backup.is_safety ? <SafetyBadge /> : null}
              </div>
            </div>

            <CardFacts>
              {showSite ? (
                <CardFact label={t("columns.type")} value={backup.type_title ?? backup.type} />
              ) : null}
              <CardFact label={t("columns.when")}>
                <span className="tabular-nums">{backup.created_at_human ?? backup.created_at}</span>
                {duration ? (
                  <span className="block text-xs tabular-nums text-muted-foreground">
                    {duration}
                  </span>
                ) : null}
              </CardFact>
              <CardFact
                label={t("columns.size")}
                value={backup.size_bytes ? formatBytes(backup.size_bytes, format) : sizeNote(backup, t)}
              />
              {/* Only when the API sends it — absent means a backend that does
                  not report it yet, and an empty fact reads as a failure to
                  load rather than a version difference. */}
              {backup.storage_destination_name !== undefined ? (
                <CardFact
                  label={t("columns.destination")}
                  value={backup.storage_destination_name ?? "—"}
                />
              ) : null}
            </CardFacts>

            {backup.status === "failed" ? (
              <p className="text-xs text-muted-foreground">
                {reasonText(backup.reason_title, t("unknownReason"))}
              </p>
            ) : null}

            {/* mt-auto pins the buttons to the bottom edge when a neighbouring
                card in the same grid row is taller. The blocker gets its own
                line above them: inline, it pushed the last button onto a second
                row by itself, which reads as a layout fault rather than a
                wrapped sentence. */}
            <div className="mt-auto flex flex-col items-end gap-2">
              {blocker && backup.status !== "failed" ? (
                <span className="text-xs text-muted-foreground">{blocker}</span>
              ) : null}
              <div className="flex flex-wrap items-center justify-end gap-2">
                {/* Labelled here rather than an icon: on a phone there is room
                    for the word, and no hover to explain a lone glyph. */}
                {canDelete && !BACKUP_IN_FLIGHT.includes(backup.status) ? (
                  <Button
                    size="icon-sm"
                    variant="outline"
                    aria-label={t("delete.action")}
                    onClick={() => setDeleting(backup)}
                  >
                    <Trash2 className="size-4" />
                  </Button>
                ) : null}

                <DownloadBackupButton backup={backup} canDownload={canRestore} label />

                {["pending", "running"].includes(backup.status) ? (
                  canClear && isBackupStale(backup) ? (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={busyId === backup.id}
                      onClick={() => onClear?.(backup)}
                    >
                      <ActionIcon icon={CircleAlert} pending={busyId === backup.id} className="size-4" />
                      {t("clear.action")}
                    </Button>
                  ) : null
                ) : backup.status === "failed" ? (
                  canRun ? (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={busyId === backup.id || Boolean(retryBlockedFor?.(backup))}
                      disabledReason={retryBlockedFor?.(backup) ?? null}
                      onClick={() => onRetry(backup)}
                    >
                      <ActionIcon icon={RotateCw} pending={busyId === backup.id} className="size-4" />
                      {t("retry")}
                    </Button>
                  ) : null
                ) : (
                  <Button
                    size="sm"
                    variant="destructive"
                    disabled={Boolean(blocker)}
                    disabledReason={blocker}
                    onClick={() => onRestore(backup)}
                  >
                    <History className="size-4" />
                    {t("restoreShort")}
                  </Button>
                )}
              </div>
            </div>
          </CardListItem>
        );
      })}
    </CardList>

    <DeleteBackupsDialog
      open={Boolean(deleting)}
      onOpenChange={(open) => (open ? null : setDeleting(null))}
      backups={deleting ? [deleting] : []}
      onDeleted={(ids) => onDeleted?.(ids)}
    />
    </>
  );
}
