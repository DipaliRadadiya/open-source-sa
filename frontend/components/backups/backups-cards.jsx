import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { CircleAlert, History, RotateCw, Trash2 } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { apiDuration } from "@/lib/format/api-date";
import { backupFailureText } from "@/lib/backups/reason";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
import { Button } from "@/components/ui/button";
import { ActionIcon } from "@/components/ui/action-icon";
import { CardFact, CardFacts, CardList, CardListItem } from "@/components/data-table/card-list";
import { BackupStatusBadge, SafetyBadge } from "@/components/backups/backup-status-badge";
import { DownloadBackupButton } from "@/components/backups/download-backup-button";
import { restoreBlocker } from "@/lib/backups/restore-blocker";
import { backupSubject } from "@/lib/backups/backup-subject";
import { ArchivedBadge, subjectLine } from "@/components/backups/backups-history-table";
import { sizeNote } from "@/components/backups/backups-history-table";
import { DeleteBackupsDialog } from "@/components/backups/delete-backups-dialog";
import { backupTypeLabel } from "@/lib/backups/type-label";

// `showSite` is off on a site's own page.
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
  // A card has no room for row selection, so each card gets its own delete button.
  canDelete = false,
  onDeleted,
  subject = "application",
  canDownload,
  // Off on the Archived tab: those are restored from a database's own tab, or not at all.
  showRestore = true,
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
        const blocker = !showRestore
          ? null
          : canRestore
          ? restoreBlocker(backup, tr, restoreInFlight, subject === "database")
          : t("noPermission");
        const about = backupSubject(backup);

        return (
          <CardListItem key={backup.id}>
            <div className="flex items-start justify-between gap-3">
              {showSite ? (
                <div className="min-w-0">
                  <p className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1 font-medium">
                    <span className="truncate">{about.name ?? t("unknownApplication")}</span>
                    {about.archived ? <ArchivedBadge /> : null}
                  </p>
                  <p className="truncate text-xs text-muted-foreground">{subjectLine(about, t)}</p>
                </div>
              ) : (
                // Wraps rather than truncating: the type is the card's title.
                <p className="min-w-0 break-words font-medium">
                  {backupTypeLabel(backup, t)}
                </p>
              )}
              <div className="flex shrink-0 flex-col items-end gap-1">
                <BackupStatusBadge backup={backup} />
                {backup.is_safety ? <SafetyBadge /> : null}
              </div>
            </div>

            <CardFacts>
              {showSite ? (
                <CardFact label={t("columns.type")} value={backupTypeLabel(backup, t)} />
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
              {/* Only when the API sends it; absent means an older backend. */}
              {backup.storage_destination_name !== undefined ? (
                <CardFact
                  label={t("columns.destination")}
                  value={backup.storage_destination_name ?? "—"}
                />
              ) : null}
            </CardFacts>

            {backup.status === "failed" ? (
              <p className="text-xs text-muted-foreground">
                {backupFailureText(backup, t("unknownReason"))}
              </p>
            ) : null}

            {/* mt-auto pins the buttons to the bottom; the blocker gets its own line. */}
            <div className="mt-auto flex flex-col items-end gap-2">
              {blocker && backup.status !== "failed" ? (
                <span className="text-xs text-muted-foreground">{blocker}</span>
              ) : null}
              <div className="flex flex-wrap items-center justify-end gap-2">
                {/* Labelled rather than icon-only: no hover on a phone. */}
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

                <DownloadBackupButton backup={backup} canDownload={canDownload ?? canRestore} label />

                {["pending", "running"].includes(backup.status) ? (
                  canClear && backup.clearable ? (
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
                ) : showRestore ? (
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
                ) : null}
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
