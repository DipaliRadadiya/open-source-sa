"use client";

import { useFormatter, useTranslations } from "next-intl";
import { History, RotateCw } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { apiDuration } from "@/lib/format/api-date";
import { reasonText } from "@/lib/backups/reason";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { BackupStatusBadge, SafetyBadge } from "@/components/backups/backup-status-badge";
import { DownloadBackupButton } from "@/components/backups/download-backup-button";
import { restoreBlocker } from "@/components/backups/restore-dialog";
import { sizeNote } from "@/components/backups/backups-history-table";

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
  busyId,
  showSite = true,
}) {
  const t = useTranslations("backups.history");
  const tr = useTranslations("backups.restore");
  const format = useFormatter();

  return (
    <div className="space-y-3">
      {backups.map((backup) => {
        const duration = apiDuration(backup.started_at, backup.finished_at);
        const blocker = canRestore ? restoreBlocker(backup, tr) : t("noPermission");

        return (
          <Card key={backup.id} className="gap-0 py-0 shadow-sm">
            <CardContent className="space-y-3 p-4">
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
                  <p className="min-w-0 truncate font-medium">
                    {backup.type_title ?? backup.type}
                  </p>
                )}
                <div className="flex shrink-0 flex-col items-end gap-1">
                  <BackupStatusBadge backup={backup} />
                  {backup.is_safety ? <SafetyBadge /> : null}
                </div>
              </div>

              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 border-t pt-3">
                {showSite ? (
                  <Fact label={t("columns.type")} value={backup.type_title ?? backup.type} />
                ) : null}
                <Fact
                  label={t("columns.when")}
                  value={backup.created_at_human ?? backup.created_at}
                  hint={duration}
                />
                <Fact
                  label={t("columns.size")}
                  value={backup.size_bytes ? formatBytes(backup.size_bytes, format) : sizeNote(backup, t)}
                />
              </dl>

              {backup.status === "failed" ? (
                <p className="text-xs text-muted-foreground">
                  {reasonText(backup.reason_title, t("unknownReason"))}
                </p>
              ) : null}

              <div className="flex flex-wrap items-center justify-end gap-2">
                {/* Labelled here rather than an icon: on a phone there is room
                    for the word, and no hover to explain a lone glyph. */}
                <DownloadBackupButton backup={backup} canDownload={canRestore} label />

                {backup.status === "failed" ? (
                  canRun ? (
                    <Button
                      size="sm"
                      variant="outline"
                      disabled={busyId === backup.id}
                      onClick={() => onRetry(backup)}
                    >
                      <RotateCw className="size-4" />
                      {t("retry")}
                    </Button>
                  ) : null
                ) : (
                  <>
                    {blocker ? (
                      <span className="text-xs text-muted-foreground">{blocker}</span>
                    ) : null}
                    <Button
                      size="sm"
                      variant={blocker ? "outline" : "destructive"}
                      disabled={Boolean(blocker)}
                      onClick={() => onRestore(backup)}
                    >
                      <History className="size-4" />
                      {t("restoreShort")}
                    </Button>
                  </>
                )}
              </div>
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}

function Fact({ label, value, hint }) {
  return (
    <div className="min-w-0">
      <dt className="text-xs text-muted-foreground">{label}</dt>
      <dd className="truncate text-sm tabular-nums">{value}</dd>
      {hint ? <dd className="truncate text-xs tabular-nums text-muted-foreground">{hint}</dd> : null}
    </div>
  );
}
