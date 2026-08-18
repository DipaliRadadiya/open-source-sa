"use client";

import Link from "next/link";
import { useTranslations } from "next-intl";
import { RotateCcw, Undo2 } from "lucide-react";
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
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { DataTable } from "@/components/ui/data-table";
import { FacetSelect } from "@/components/data-table/facet-select";
import { EmptyState } from "@/components/data-table/empty-state";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { RESTORE_OUTCOME, outcomeOf } from "@/components/backups/status-meta";

/**
 * Every restore this server has run.
 *
 * Restoring is the only thing in the panel that destroys data, and until now
 * it left no trace: `/restores` was read once to drive the live progress
 * banner and then thrown away. So "was this site restored, when, by which
 * backup, and did it work?" had no answer anywhere — the activity log cannot
 * cover it either, since that endpoint only ever shows your own actions.
 *
 * Read-only on purpose. The way to undo a restore is the safety copy it took,
 * which is a backup like any other and belongs in the flow that already
 * guards restores rather than in a second, shorter path from a table row.
 */
/**
 * What to call this restore's status.
 *
 * `t()` THROWS on a key it does not have, and this key is built from an API
 * value — so a status we have not translated took the whole page down with
 * "Something went wrong" rather than showing one odd-looking badge. The backup
 * badge beside it has always guarded with `t.has()`; this list never did.
 * Falls back to the raw status, which is at least true.
 */
function statusLabel(restore, t) {
  if (restore.status_title) return restore.status_title;
  const key = `statuses.${restore.status}`;
  return t.has(key) ? t(key) : restore.status;
}

export function RestoresList({ restores, applications = [], hasFilters = false }) {
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

      {/* The same four filters, in the same order and widths, as the backup
          history tab: this is the same question asked of the other half of the
          data, and two different filter rows for it would be two things to
          learn. All URL-driven, so a filtered view is a link. */}
      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        <FacetSelect
          paramKey="application"
          allLabel={t("allApplications")}
          options={applications.map((application) => ({
            value: String(application.id),
            label: application.name,
          }))}
          className="w-full sm:w-56"
        />
        <FacetSelect
          paramKey="status"
          allLabel={t("allStatuses")}
          options={RESTORE_STATUSES.map((value) => ({ value, label: t(`statuses.${value}`) }))}
          className="w-full sm:w-44"
        />
        <FacetSelect
          paramKey="period"
          allLabel={t("anyTime")}
          options={BACKUP_PERIODS.map((value) => ({
            value,
            label: t("lastDays", { count: Number(value) }),
          }))}
          className="w-full sm:w-44"
        />
        <FacetSelect
          paramKey="type"
          allLabel={t("allTypes")}
          options={BACKUP_TYPES.map((value) => ({ value, label: t(`types.${value}`) }))}
          className="w-full sm:w-48"
        />
      </div>

      {restores.length === 0 ? (
        <EmptyState
          icon={RotateCcw}
          title={hasFilters ? t("emptyFiltered.title") : t("empty.title")}
          description={hasFilters ? t("emptyFiltered.description") : t("empty.description")}
        />
      ) : (
        <>
          <div className="lg:hidden">
            <RestoreCards restores={restores} />
          </div>
          <div className="hidden lg:block">
            <DataTable columns={columns} data={restores} emptyMessage={t("empty.title")} />
          </div>
        </>
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
      {/* A restore that failed part-way is the most alarming row anyone will
          ever read here, so it says which step gave out rather than leaving
          them to guess how far it got. */}
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
      {/* A queued restore has no start time at all, and an empty cell beside
          five filled ones reads as a rendering fault rather than as "it has
          not begun". */}
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

/**
 * Whether the site can still be put back the way it was before this restore.
 *
 * The safety copy is taken automatically before the overwrite and is exempt
 * from retention, so it does not quietly age out — which makes "yes, still"
 * a true statement rather than a hopeful one.
 */
function UndoCell({ row }) {
  const t = useTranslations("backups.restores");
  const restore = row.original;

  if (!restore.safety_backup_id) {
    return <span className="text-sm text-muted-foreground/70">{t("noSafetyCopy")}</span>;
  }

  return (
    <Button asChild variant="outline" size="sm">
      <Link href={`/backups/history?application=${restore.application_id ?? ""}`}>
        <Undo2 className="size-4" />
        {t("findSafetyCopy")}
      </Link>
    </Button>
  );
}

function RestoreCards({ restores }) {
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
            </CardContent>
          </Card>
        );
      })}
    </div>
  );
}
