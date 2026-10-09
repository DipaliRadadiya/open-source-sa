import { useState } from "react";
import Link from "@/components/ui/app-link";
import { useFormatter, useTranslations } from "next-intl";
import { CopyButton } from "@/components/ui/copy-button";
import { CircleAlert, History, RotateCw, Trash2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { formatBytes } from "@/lib/format/bytes";
import { apiDuration } from "@/lib/format/api-date";
import { backupFailureText } from "@/lib/backups/reason";
import { BACKUP_IN_FLIGHT, backupHasArchive } from "@/lib/schemas/backup";
import { Button } from "@/components/ui/button";
import { DataTable } from "@/components/ui/data-table";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { BackupStatusBadge, SafetyBadge } from "@/components/backups/backup-status-badge";
import { DownloadBackupButton } from "@/components/backups/download-backup-button";
import { DeleteBackupsDialog } from "@/components/backups/delete-backups-dialog";
import { Checkbox } from "@/components/ui/checkbox";
import { ActionIcon } from "@/components/ui/action-icon";
import { restoreBlocker } from "@/components/backups/restore-dialog";

/* Every backup that has run, as the same table the overview uses. */

function SiteCell({ row }) {
  const t = useTranslations("backups.history");
  const backup = row.original;

  return (
    <div className="min-w-0">
      <div className="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-1">
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

function StatusCell({ row, table }) {
  const t = useTranslations("backups.history");
  const backup = row.original;
  const reason =
    backup.status === "failed"
      ? backupFailureText(backup, t("unknownReason"))
      : null;

  // One width on every list; badges wrap and the reason is clamped to two
  // lines, with the full text on hover/focus.
  const width = "w-32 max-w-32";

  return (
    <div className={cn(width, "min-w-0 space-y-1")}>
      <div className="flex flex-wrap items-center gap-1.5">
        <BackupStatusBadge backup={backup} />
        {/* A site's own page hides the Site column, which carries this badge otherwise. */}
        {backup.is_safety && !table.options.meta?.showSite ? <SafetyBadge /> : null}
      </div>
      {/* `truncate` without a bounded width lets a long reason widen the auto-layout table. */}
      {reason ? (
        <Tooltip>
          <TooltipTrigger asChild>
            <p
              tabIndex={0}
              className="line-clamp-2 whitespace-normal break-words text-xs leading-4 text-muted-foreground"
            >
              {reason}
            </p>
          </TooltipTrigger>
          <TooltipContent className="max-w-80 whitespace-normal">
            {reason}
          </TooltipContent>
        </Tooltip>
      ) : null}
    </div>
  );
}

function TypeCell({ row }) {
  return (
    <span className="text-sm">{row.original.type_title ?? row.original.type}</span>
  );
}

// Enough to match an object in a bucket listing; the full value is what gets copied.
function uidFragment(uid) {
  const text = String(uid);
  return text.length > 8 ? `${text.slice(0, 8)}…` : text;
}

// The backup's own target, not the site's current one, which may have changed since.
function DestinationCell({ row }) {
  const t = useTranslations("backups.history");
  const { storage_destination_name: name, uid } = row.original;
  if (!name) return <span className="text-sm text-muted-foreground">—</span>;
  // `title` suffices: this column only renders on pointer-sized screens. Never the full uid: 36 mono chars overflow.
  return (
    <span className="flex min-w-0 flex-col gap-0.5">
      <span className="truncate text-sm" title={name}>
        {name}
      </span>
      {/* A failed upload still has a uid that matches nothing. Same predicate as Download. */}
      {uid && backupHasArchive(row.original.status) ? (
        <span className="flex min-w-0 items-center gap-0.5">
          <span className="truncate font-mono text-xs text-muted-foreground" title={uid}>
            {uidFragment(uid)}
          </span>
          <CopyButton value={uid} label={t("copyUid")} className="size-6 shrink-0" />
        </span>
      ) : null}
    </span>
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

  // A finished backup has a size; a running one has upload progress.
  const progress = uploadProgress(row.original, t, format);

  return (
    <span className="text-sm tabular-nums text-muted-foreground">
      {size ? formatBytes(size, format) : (progress ?? sizeNote(row.original, t))}
    </span>
  );
}

// Null if not reported yet; a bare byte count when the total is unknown.
export function uploadProgress(backup, t, format) {
  const done = backup.bytes_transferred;

  if (!BACKUP_IN_FLIGHT.includes(backup.status) || !done) return null;

  const total = backup.bytes_total;

  if (!total) return t("uploadProgressBare", { done: formatBytes(done, format) });

  return t("uploadProgress", {
    done: formatBytes(done, format),
    total: formatBytes(total, format),
  });
}

/** Why there is no size: still in flight, or failed with no archive. */
export function sizeNote(backup, t) {
  if (BACKUP_IN_FLIGHT.includes(backup.status)) return t("sizePending");
  if (backup.status === "failed") return t("sizeNone");
  return t("sizeUnknown");
}

// Retry dispatches the same job as "Back up now" and creates a NEW row, so anything
// waiting on a started run must treat the two identically.
function ActionsCell({ row, table }) {
  const t = useTranslations("backups.history");
  const tr = useTranslations("backups.restore");
  const {
    canRestore,
    canRun,
    canClear,
    onRestore,
    onRetry,
    onClear,
    busyId,
    restoreInFlight,
    retryBlockedFor,
  } = table.options.meta;
  const backup = row.original;

  // Download is offered in every state that has an archive. It needs
  // `backup,manage` like restore: the archive holds every file and a database dump.
  const download = <DownloadBackupButton backup={backup} canDownload={canRestore} />;
  const retryBlocked = retryBlockedFor?.(backup) ?? null;

  if (["pending", "running"].includes(backup.status)) {
    return (
      <div className="flex items-center justify-end gap-2">
        {download}
        {canClear && backup.clearable ? (
          <Button
            size="sm"
            variant="outline"
            className="min-w-28"
            disabled={busyId === backup.id}
            onClick={() => onClear?.(backup)}
          >
            <ActionIcon icon={CircleAlert} pending={busyId === backup.id} className="size-4" />
            {t("clear.action")}
          </Button>
        ) : null}
      </div>
    );
  }

  if (backup.status === "failed") {
    return (
      <div className="flex items-center justify-end gap-2">
        {download}
        {canRun ? (
          <Button
            size="sm"
            variant="outline"
            // Same width as Restore, so the download icons line up down the column.
            className="min-w-28"
            disabled={busyId === backup.id || Boolean(retryBlocked)}
            disabledReason={retryBlocked}
            onClick={() => onRetry(backup)}
          >
            <ActionIcon icon={RotateCw} pending={busyId === backup.id} className="size-4" />
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
      {/* Reason in the tooltip (opens on tap too), so the buttons stay aligned. */}
      <ReasonTooltip reason={blocker}>
        <Button
          size="sm"
          variant="destructive"
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

function SelectHeaderCell({ table }) {
  const t = useTranslations("backups.history");
  const all = table.getIsAllRowsSelected();

  return (
    <Checkbox
      checked={all || (table.getIsSomeRowsSelected() ? "indeterminate" : false)}
      onCheckedChange={(value) => table.toggleAllRowsSelected(!!value)}
      aria-label={t("delete.selectAll")}
    />
  );
}

function SelectCell({ row }) {
  const t = useTranslations("backups.history");

  return (
    <Checkbox
      checked={row.getIsSelected()}
      onCheckedChange={(value) => row.toggleSelected(!!value)}
      aria-label={t("delete.selectRow")}
    />
  );
}

export function BackupsHistoryTable({
  backups,
  canRestore,
  restoreInFlight,
  canRun,
  onRestore,
  onRetry,
  onClear,
  busyId,
  // `(backup) => reason | null`. Per row because the server-wide list spans
  // every site: one site's run must not disable Retry on another's rows.
  retryBlockedFor = null,
  // Hides the Site column on a site's own page.
  showSite = true,
  emptyMessage,
  emptyIcon,
  // Set when the caller already wraps this in a Card.
  bare = false,
  // Deleting also removes the archive from object storage, so it needs the
  // restore permission, not the schedule one.
  canDelete = false,
  canClear = false,
  onDeleted,
}) {
  const t = useTranslations("backups.history");
  const [selection, setSelection] = useState({});

  // Keyed by backup id, not row index, so a refetch that reorders the list
  // cannot change which rows get deleted.
  const selected = backups.filter((backup) => selection[String(backup.id)]);
  const [confirming, setConfirming] = useState(false);
  // The API loads the destination relation for the whole page or none of it.
  const showDestination = backups.some(
    (backup) => backup.storage_destination_name !== undefined,
  );

  const columns = [
    canDelete
      ? {
          id: "select",
          header: SelectHeaderCell,
          meta: { className: "w-10" },
          cell: SelectCell,
        }
      : null,
    showSite
      ? {
          accessorKey: "application_name",
          header: t("columns.site"),
          meta: { className: "min-w-48" },
          cell: SiteCell,
        }
      : null,
    // Sized to the badge, not reserved, to leave room for the actions column.
    { accessorKey: "status", header: t("columns.status"), meta: { className: "w-32" }, cell: StatusCell },
    // No fixed width, so long labels and the header wrap instead of crowding
    // the actions column.
    { id: "type", header: t("columns.type"), meta: { className: "w-32 whitespace-normal" }, cell: TypeCell },
    // Only when the API sends it: the field is absent (not null) on older
    // backends, and an always-empty column would look like a load failure.
    showDestination
      ? {
          id: "destination",
          header: t("columns.destination"),
          // Hidden below 2xl on the server-wide list only: with the Site column
          // it overflows the card at common laptop widths.
          meta: { className: cn("max-w-40", showSite && "hidden 2xl:table-cell") },
          cell: DestinationCell,
        }
      : null,
    { id: "when", header: t("columns.when"), meta: { className: "w-28" }, cell: WhenCell },
    {
      id: "size",
      header: () => <span className="block text-right">{t("columns.size")}</span>,
      // The "No archive" note may wrap so it does not widen the column.
      meta: { className: "w-20 text-right whitespace-normal" },
      cell: SizeCell,
    },
    {
      id: "actions",
      header: () => <span className="sr-only">{t("columns.actions")}</span>,
      // Fits the download button beside the state action; the blocked reason
      // lives in a tooltip, so no text shares this column.
      meta: { className: "w-48 text-right" },
      cell: ActionsCell,
    },
  ].filter(Boolean);

  return (
    <>
      {/* Only once something is selected. */}
      {canDelete && selected.length > 0 ? (
        <div
          className={cn(
            "flex flex-wrap items-center justify-between gap-3 bg-muted/40",
            // `bare`: the table sits in an unpadded CardContent, so the bar is
            // full-bleed with a bottom rule, matching the queued-run strip.
            bare
              ? "border-b px-5 py-3"
              : "mb-3 rounded-lg border px-4 py-2.5",
          )}
        >
          <span className="text-sm">{t("delete.selectedCount", { count: selected.length })}</span>
          <div className="flex items-center gap-2">
            <Button variant="ghost" size="sm" onClick={() => setSelection({})}>
              {t("delete.clearSelection")}
            </Button>
            <Button variant="destructive" size="sm" onClick={() => setConfirming(true)}>
              <Trash2 className="size-4" />
              {t("delete.action")}
            </Button>
          </div>
        </div>
      ) : null}

      <DataTable
        columns={columns}
        data={backups}
        emptyMessage={emptyMessage ?? t("empty.title")}
        emptyIcon={emptyIcon}
        bare={bare}
        meta={{
          canRestore,
          canRun,
          canClear,
          onRestore,
          onRetry,
          onClear,
          busyId,
          restoreInFlight,
          retryBlockedFor,
          showSite,
        }}
        {...(canDelete
          ? {
              rowSelection: selection,
              onRowSelectionChange: setSelection,
              rowId: (backup) => String(backup.id),
            }
          : null)}
      />

      <DeleteBackupsDialog
        open={confirming}
        onOpenChange={setConfirming}
        backups={selected}
        onDeleted={(ids, failedIds = []) => {
          // Keep the refusals selected so a partial failure can be acted on again.
          setSelection(Object.fromEntries(failedIds.map((id) => [String(id), true])));
          onDeleted?.(ids);
        }}
      />
    </>
  );
}
