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
import { ListCard } from "@/components/data-table/list-card";
import { EmptyState } from "@/components/data-table/empty-state";
import { ClearFiltersButton } from "@/components/data-table/clear-filters-button";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { RESTORE_OUTCOME, outcomeOf } from "@/components/backups/status-meta";
import { UndoRestoreButton } from "@/components/backups/undo-restore-button";
import { FACT_LABEL } from "@/lib/theme/facts";
import { backupSubject } from "@/lib/backups/backup-subject";
import { subjectLine } from "@/components/backups/backups-history-table";
import { SubjectFilter } from "@/components/backups/subject-filter";

function statusLabel(restore, t) {
  if (restore.status_title) return restore.status_title;
  const key = `statuses.${restore.status}`;
  return t.has(key) ? t(key) : restore.status;
}

export function RestoresList({ restores, applications = [], databases = [], hasFilters = false, canRestore = false, pager = null }) {
  const t = useTranslations("backups.restores");

  const running = restores.some((restore) => RESTORE_IN_FLIGHT.includes(restore.status));

  const columns = [
    { accessorKey: "application_name", header: t("columns.site"), meta: { className: "min-w-52" }, cell: SiteCell },
    { accessorKey: "status", header: t("columns.status"), meta: { className: "w-56" }, cell: StatusCell },
    { id: "type", header: t("columns.type"), meta: { className: "min-w-40" }, cell: TypeCell },
    { id: "when", header: t("columns.when"), meta: { className: "w-40" }, cell: WhenCell },
    { id: "undo", header: t("columns.undo"), meta: { className: "w-44" }, cell: UndoCell },
  ];

  const toolbar = (
    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
      <SubjectFilter
        applications={applications}
        databases={databases}
        label={t("columns.site")}
        allLabel={t("allApplications")}
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
      <div className="flex items-center gap-3 sm:contents">
        <FacetSelect
          paramKey="type"
          label={t("columns.type")}
          allLabel={t("allTypes")}
          options={BACKUP_TYPES.map((value) => ({ value, label: t(`types.${value}`) }))}
          className="min-w-0 flex-1 sm:w-auto sm:min-w-36 sm:flex-none sm:shrink-0"
        />
        <div className="sm:ml-auto">
          <RefreshButton />
        </div>
      </div>
    </div>
  );

  return (
    <div className="space-y-4">
      {running ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      {restores.length === 0 ? (
        <ListCard toolbar={toolbar}>
          <EmptyState
            icon={RotateCcw}
            title={hasFilters ? t("emptyFiltered.title") : t("empty.title")}
            description={hasFilters ? t("emptyFiltered.description") : t("empty.description")}
            badge={hasFilters ? "search" : null}
            action={hasFilters ? <ClearFiltersButton keys={["application", "database", "status", "period", "type", "search"]} /> : null}
          />
        </ListCard>
      ) : (
        <ListCard from="c900" toolbar={toolbar} footer={pager}>
          <div className="@min-[900px]/page:hidden">
            <RestoreCards restores={restores} canRestore={canRestore} />
          </div>
          <div className="hidden @min-[900px]/page:block">
            <DataTable bare columns={columns} data={restores} emptyMessage={t("empty.title")} meta={{ canRestore }} />
          </div>
        </ListCard>
      )}
    </div>
  );
}

function SiteCell({ row }) {
  const t = useTranslations("backups.restores");
  const th = useTranslations("backups.history");
  const subject = backupSubject(row.original);
  const name = subject.name ?? t("unknownApplication");

  return (
    <div className="min-w-0">
      {subject.href ? (
        <Link href={subject.href} className="truncate font-medium underline-offset-4 hover:underline">
          {name}
        </Link>
      ) : (
        <span className="truncate font-medium">{name}</span>
      )}
      <p className="truncate text-xs text-muted-foreground">{subjectLine(subject, th)}</p>
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

function UndoCell({ row, table }) {
  const t = useTranslations("backups.restores");
  const restore = row.original;

  if (!restore.safety_backup_id) {
    return <span className="text-sm text-muted-foreground">{t("noSafetyCopy")}</span>;
  }
  if (restore.database_id) {
    return (
      <Link href={`/databases/${restore.database_id}?tab=backups`} className="text-sm underline-offset-4 hover:underline">
        {t("undoOnDatabase")}
      </Link>
    );
  }
  if (!restore.application_id) {
    return <span className="text-sm text-muted-foreground">{t("undoUnavailable")}</span>;
  }

  return <UndoRestoreButton restore={restore} canRestore={table.options.meta?.canRestore} />;
}

function RestoreCards({ restores, canRestore = false }) {
  const t = useTranslations("backups.restores");
  const th = useTranslations("backups.history");

  return (
    <div className="space-y-3">
      {restores.map((restore) => {
        const meta = outcomeOf(RESTORE_OUTCOME, restore.status);
        const Icon = meta.icon;
        const duration = apiDuration(restore.started_at, restore.finished_at);
        const subject = backupSubject(restore);

        return (
          <Card key={restore.id} className="gap-0 py-0 shadow-sm">
            <CardContent className="space-y-3 p-4">
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0">
                  <p className="truncate font-medium">{subject.name ?? t("unknownApplication")}</p>
                  <p className="truncate text-xs text-muted-foreground">{subjectLine(subject, th)}</p>
                </div>
                <Badge variant={meta.variant} className="shrink-0 gap-1.5 font-normal">
                  <Icon className={cn("size-3", meta.spin && "animate-spin")} />
                  {statusLabel(restore, t)}
                </Badge>
              </div>

              <dl className="grid grid-cols-2 gap-x-4 gap-y-2 border-t pt-3">
                <div className="min-w-0">
                  <dt className={FACT_LABEL}>{t("columns.type")}</dt>
                  <dd className="truncate text-sm">{restore.type_title ?? restore.type}</dd>
                </div>
                <div className="min-w-0">
                  <dt className={FACT_LABEL}>{t("columns.when")}</dt>
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

              {restore.safety_backup_id && restore.database_id ? (
                <Link href={`/databases/${restore.database_id}?tab=backups`} className="block text-sm underline-offset-4 hover:underline">
                  {t("undoOnDatabase")}
                </Link>
              ) : restore.safety_backup_id && restore.application_id ? (
                <UndoRestoreButton restore={restore} canRestore={canRestore} className="w-full sm:w-auto" />
              ) : null}
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}
