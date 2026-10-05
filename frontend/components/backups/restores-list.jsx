"use client";

import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { RotateCcw } from "lucide-react";
import { cn } from "@/lib/utils";
import { apiDuration } from "@/lib/format/api-date";
import { reasonText } from "@/lib/backups/reason";
import {
  BACKUP_PERIODS,
  BACKUP_TYPES,
  RESTORE_IN_FLIGHT,
  RESTORE_STATUSES,
} from "@/lib/schemas/backup";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent } from "@/components/ui/card";
import { DataTable } from "@/components/ui/data-table";
import { FacetSelect } from "@/components/data-table/facet-select";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { EmptyState } from "@/components/data-table/empty-state";
import { ClearFiltersButton } from "@/components/data-table/clear-filters-button";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { RESTORE_OUTCOME, outcomeOf } from "@/components/backups/status-meta";
import { UndoRestoreButton } from "@/components/backups/undo-restore-button";

// The key comes from the API and `t()` throws on a miss, so fall back to the raw status.
function statusLabel(restore, t) {
  if (restore.status_title) return restore.status_title;
  const key = `statuses.${restore.status}`;
  return t.has(key) ? t(key) : restore.status;
}

export function RestoresList({ restores, applications = [], hasFilters = false, canRestore = false }) {
  const t = useTranslations("backups.restores");

  const running = restores.some((restore) => RESTORE_IN_FLIGHT.includes(restore.status));

  const columns = [
    { accessorKey: "application_name", header: t("columns.site"), meta: { className: "min-w-52" }, cell: SiteCell },
    { accessorKey: "status", header: t("columns.status"), meta: { className: "w-56" }, cell: StatusCell },
    { id: "type", header: t("columns.type"), meta: { className: "min-w-40" }, cell: TypeCell },
    { id: "when", header: t("columns.when"), meta: { className: "w-40" }, cell: WhenCell },
    { id: "undo", header: t("columns.undo"), meta: { className: "w-44" }, cell: UndoCell },
  ];

  return (
    <div className="space-y-4">
      {running ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      {/* Same filters, order and widths as the backup history tab; URL-driven, so a view is a link. */}
      <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
        <FacetSelect
          paramKey="application"
          label={t("columns.site")}
          allLabel={t("allApplications")}
          options={applications.map((application) => ({
            value: String(application.id),
            label: application.name,
          }))}
          className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
        />
        <FacetSelect
          paramKey="status"
          label={t("columns.status")}
          allLabel={t("allStatuses")}
          options={RESTORE_STATUSES.map((value) => ({ value, label: t(`statuses.${value}`) }))}
          className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
        />
        <FacetSelect
          paramKey="period"
          label={t("columns.when")}
          allLabel={t("anyTime")}
          options={BACKUP_PERIODS.map((value) => ({
            value,
            label: t("lastDays", { count: Number(value) }),
          }))}
          className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
        />
        <FacetSelect
          paramKey="type"
          label={t("columns.type")}
          allLabel={t("allTypes")}
          options={BACKUP_TYPES.map((value) => ({ value, label: t(`types.${value}`) }))}
          className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
        />
        <div className="sm:ml-auto">
          <RefreshButton />
        </div>
      </div>

      {restores.length === 0 ? (
        <EmptyState
          icon={RotateCcw}
          title={hasFilters ? t("emptyFiltered.title") : t("empty.title")}
          description={hasFilters ? t("emptyFiltered.description") : t("empty.description")}
          // Only when filters emptied the list.
          action={hasFilters ? <ClearFiltersButton keys={["application", "status", "period", "type", "search"]} /> : null}
        />
      ) : (
        <div className="@container">
          {/* From the widest locale: German needs ~990px of table (Actions "Wiederherstellen",
              Restores "Was zurückgeholt wurde"), so the table starts at 1000px of content. */}
          <div className="@min-[1000px]:hidden">
            <RestoreCards restores={restores} canRestore={canRestore} />
          </div>
          <div className="hidden @min-[1000px]:block">
            <DataTable columns={columns} data={restores} emptyMessage={t("empty.title")} meta={{ canRestore }} />
          </div>
        </div>
      )}
    </div>
  );
}

function SiteCell({ row }) {
  const t = useTranslations("backups.restores");
  const restore = row.original;

  return (
    <div className="min-w-0">
      {restore.application_id ? (
        <Link
          href={`/applications/${restore.application_id}/backups`}
          className="truncate font-medium underline-offset-4 hover:underline"
        >
          {restore.application_name ?? t("unknownApplication")}
        </Link>
      ) : (
        <span className="truncate font-medium">{t("unknownApplication")}</span>
      )}
      <p className="truncate text-xs text-muted-foreground">{restore.application_domain ?? ""}</p>
    </div>
  );
}

function StatusCell({ row }) {
  const t = useTranslations("backups.restores");
  const restore = row.original;
  const meta = outcomeOf(RESTORE_OUTCOME, restore.status);
  const Icon = meta.icon;

  return (
    <div className="min-w-0 space-y-1">
      <Badge variant={meta.variant} className="gap-1.5 font-normal">
        <Icon className={cn("size-3", meta.spin && "animate-spin")} />
        {statusLabel(restore, t)}
      </Badge>
      {/* A failed restore names the step that failed. */}
      {restore.status === "failed" ? (
        <p className="truncate text-xs text-muted-foreground">
          {reasonText(restore.reason_title, t("unknownReason"))}
        </p>
      ) : RESTORE_IN_FLIGHT.includes(restore.status) && restore.current_step_title ? (
        <p className="truncate text-xs text-muted-foreground">{restore.current_step_title}</p>
      ) : null}
    </div>
  );
}

function TypeCell({ row }) {
  return <span className="text-sm">{row.original.type_title ?? row.original.type}</span>;
}

function WhenCell({ row }) {
  const t = useTranslations("backups.restores");
  const restore = row.original;
  const duration = apiDuration(restore.started_at, restore.finished_at);

  return (
    <div className="min-w-0">
      {/* A queued restore has no start time; say "not started" rather than leave it blank. */}
      <p className="truncate text-sm tabular-nums">
        {restore.started_at_human ?? restore.started_at ?? (
          <span className="text-muted-foreground">{t("notStarted")}</span>
        )}
      </p>
      {duration ? (
        <p className="truncate text-xs tabular-nums text-muted-foreground">{duration}</p>
      ) : null}
    </div>
  );
}

// Only the newest two safety copies are kept (the API then sends no id).
function UndoCell({ row, table }) {
  const t = useTranslations("backups.restores");
  const restore = row.original;

  if (!restore.safety_backup_id) {
    return <span className="text-sm text-muted-foreground">{t("noSafetyCopy")}</span>;
  }

  return <UndoRestoreButton restore={restore} canRestore={table.options.meta?.canRestore} />;
}

function RestoreCards({ restores, canRestore = false }) {
  const t = useTranslations("backups.restores");

  return (
    <div className="space-y-3">
      {restores.map((restore) => {
        const meta = outcomeOf(RESTORE_OUTCOME, restore.status);
        const Icon = meta.icon;
        const duration = apiDuration(restore.started_at, restore.finished_at);

        return (
          <Card key={restore.id} className="gap-0 py-0 shadow-sm">
            <CardContent className="space-y-3 p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate font-medium">
                    {restore.application_name ?? t("unknownApplication")}
                  </p>
                  <p className="truncate text-xs text-muted-foreground">
                    {restore.application_domain ?? ""}
                  </p>
                </div>
                <Badge variant={meta.variant} className="shrink-0 gap-1.5 font-normal">
                  <Icon className={cn("size-3", meta.spin && "animate-spin")} />
                  {statusLabel(restore, t)}
                </Badge>
              </div>

              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 border-t pt-3">
                <div className="min-w-0">
                  <dt className="text-xs text-muted-foreground">{t("columns.type")}</dt>
                  <dd className="truncate text-sm">{restore.type_title ?? restore.type}</dd>
                </div>
                <div className="min-w-0">
                  <dt className="text-xs text-muted-foreground">{t("columns.when")}</dt>
                  <dd className="truncate text-sm tabular-nums">
                    {restore.started_at_human ?? restore.started_at ?? (
                      <span className="text-muted-foreground">{t("notStarted")}</span>
                    )}
                  </dd>
                  {duration ? (
                    <dd className="truncate text-xs tabular-nums text-muted-foreground">
                      {duration}
                    </dd>
                  ) : null}
                </div>
              </dl>

              {restore.status === "failed" ? (
                <p className="text-xs text-muted-foreground">
                  {reasonText(restore.reason_title, t("unknownReason"))}
                </p>
              ) : null}

              {/* Cards had no way back at all; same action as the table's column. */}
              {restore.safety_backup_id ? (
                <UndoRestoreButton restore={restore} canRestore={canRestore} className="w-full sm:w-auto" />
              ) : null}
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}
