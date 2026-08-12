"use client";

import Link from "next/link";
import { useFormatter, useTranslations } from "next-intl";
import { History, RotateCw } from "lucide-react";
import { formatBytes } from "@/lib/format/bytes";
import { apiDuration } from "@/lib/format/api-date";
import { reasonText } from "@/lib/backups/reason";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { BackupStatusBadge, SafetyBadge } from "@/components/backups/backup-status-badge";
import { DownloadBackupButton } from "@/components/backups/download-backup-button";
import { restoreBlocker } from "@/components/backups/restore-dialog";

/* ---------------------------------------------------------------------------
 * Every backup that has run, as the same table the overview uses.
 *
 * These were hand-built <li> rows with their own spacing, so one feature had
 * two list rhythms. Columns also fix the alignment complaint on their own:
 * time, size and actions line up because they are columns, not because each
 * row remembered to right-align them.
 * ------------------------------------------------------------------------- */

function SiteCell({ row }) {
  const t = useTranslations("backups.history");
  const backup = row.original;

  return (
    <div className="min-w-0">
      <div className="flex min-w-0 items-center gap-2">
        {backup.application_id ? (
          <Link
            href={`/applications/${backup.application_id}/backups`}
            className="truncate font-medium underline-offset-4 hover:underline"
          >
            {backup.application_name ?? t("unknownApplication")}
          </Link>
        ) : (
          <span className="truncate font-medium">{t("unknownApplication")}</span>
        )}
        {backup.is_safety ? <SafetyBadge /> : null}
      </div>
      <p className="truncate text-xs text-muted-foreground">
        {backup.application_domain ?? ""}
      </p>
    </div>
  );
}

function StatusCell({ row }) {
  const t = useTranslations("backups.history");
  const backup = row.original;

  return (
    <div className="min-w-0 space-y-1">
      <BackupStatusBadge backup={backup} />
      {/* The reason sits under the badge in muted text, not as large red
          prose. A failed run should read as one row that went wrong, not as
          the loudest thing on the screen. */}
      {backup.status === "failed" ? (
        <p className="truncate text-xs text-muted-foreground">
          {reasonText(backup.reason_title, t("unknownReason"))}
        </p>
      ) : null}
    </div>
  );
}

function TypeCell({ row }) {
  return (
    <span className="text-sm">{row.original.type_title ?? row.original.type}</span>
  );
}

function WhenCell({ row }) {
  const backup = row.original;
  const duration = apiDuration(backup.started_at, backup.finished_at);

  return (
    <div className="min-w-0">
      <p className="truncate text-sm tabular-nums">{backup.created_at_human ?? backup.created_at}</p>
      {duration ? (
        <p className="truncate text-xs tabular-nums text-muted-foreground">{duration}</p>
      ) : null}
    </div>
  );
}

function SizeCell({ row }) {
  const t = useTranslations("backups.history");
  const format = useFormatter();
  const { size_bytes: size } = row.original;

  return (
    <span className="text-sm tabular-nums text-muted-foreground">
      {size ? formatBytes(size, format) : sizeNote(row.original, t)}
    </span>
  );
}

/**
 * Why there is no size, rather than one flat "Unknown".
 *
 * A run still in flight has not written an archive yet; a failed one never
 * will. Both are knowable, and saying "Unknown" about them makes the reader
 * guess whether it means zero, missing, or broken.
 */
export function sizeNote(backup, t) {
  if (BACKUP_IN_FLIGHT.includes(backup.status)) return t("sizePending");
  if (backup.status === "failed") return t("sizeNone");
  return t("sizeUnknown");
}

/**
 * One action per state, and never one that cannot work.
 *
 * A failed backup has nothing to restore FROM, so it does not get a Restore
 * button at all — it gets Retry, which re-runs the same configuration. That
 * used to be "Run backup" (a fresh run from the site's current state) purely
 * because no retry endpoint existed.
 */
function ActionsCell({ row, table }) {
  const t = useTranslations("backups.history");
  const tr = useTranslations("backups.restore");
  const { canRestore, canRun, onRestore, onRetry, busyId, restoreInFlight } =
    table.options.meta;
  const backup = row.original;

  // Download rides alongside whichever action the state earns, including on a
  // failed run: it needs the same trust as restore (`backup,manage`) because
  // the archive is every file plus a database dump.
  const download = <DownloadBackupButton backup={backup} canDownload={canRestore} />;

  if (backup.status === "failed") {
    return (
      <div className="flex items-center justify-end gap-2">
        {download}
        {canRun ? (
          <Button
            size="sm"
            variant="outline"
            // Same width as Restore, so the download icon beside it lands on
            // one vertical line down the column instead of shuffling with
            // whichever label the row's state earned.
            className="min-w-28"
            disabled={busyId === backup.id}
            onClick={() => onRetry(backup)}
          >
            <RotateCw className="size-4" />
            {t("retry")}
          </Button>
        ) : null}
      </div>
    );
  }

  const blocker = canRestore
    ? restoreBlocker(backup, tr, restoreInFlight)
    : t("noPermission");

  return (
    <div className="flex items-center justify-end gap-2">
      {download}
      {/* The reason lives in the tooltip, not beside the button. As inline
          text it pushed that row's buttons left and the column stopped lining
          up — and since `ReasonTooltip` now opens on tap as well as hover, the
          reason is no less reachable for being tucked away. */}
      <ReasonTooltip reason={blocker}>
        <Button
          size="sm"
          variant={blocker ? "outline" : "destructive"}
          className="min-w-28"
          disabled={Boolean(blocker)}
          onClick={() => onRestore(backup)}
        >
          <History className="size-4" />
          {t("restoreShort")}
        </Button>
      </ReasonTooltip>
    </div>
  );
}

export function BackupsHistoryTable({
  backups,
  canRestore,
  restoreInFlight,
  canRun,
  onRestore,
  onRetry,
  busyId,
  // On a site's own page every row belongs to that site, so naming it ten
  // times says nothing. The rest of the table is identical, which is the
  // point: a backup should describe itself the same way on both screens.
  showSite = true,
  emptyMessage,
  // Set when the caller already wraps this in a Card.
  bare = false,
}) {
  const t = useTranslations("backups.history");

  const columns = [
    showSite
      ? {
          accessorKey: "application_name",
          header: t("columns.site"),
          meta: { className: "min-w-52" },
          cell: SiteCell,
        }
      : null,
    { accessorKey: "status", header: t("columns.status"), meta: { className: "w-52" }, cell: StatusCell },
    { id: "type", header: t("columns.type"), meta: { className: showSite ? "w-44" : "min-w-44" }, cell: TypeCell },
    { id: "when", header: t("columns.when"), meta: { className: "w-36" }, cell: WhenCell },
    {
      id: "size",
      header: () => <span className="block text-right">{t("columns.size")}</span>,
      meta: { className: "w-28 text-right" },
      cell: SizeCell,
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      // Fits the download button beside the state action; the blocked reason
      // moved into a tooltip, so no text shares this column any more.
      meta: { className: "w-48 text-right" },
      cell: ActionsCell,
    },
  ].filter(Boolean);

  return (
    <DataTable
      columns={columns}
      data={backups}
      emptyMessage={emptyMessage ?? t("empty.title")}
      bare={bare}
      meta={{ canRestore, canRun, onRestore, onRetry, busyId, restoreInFlight }}
    />
  );
}
