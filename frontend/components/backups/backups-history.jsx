"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations, useFormatter } from "next-intl";
import { toast } from "sonner";
import { Archive, CircleAlert, CircleCheck, CircleX, Loader2, RotateCw } from "lucide-react";
import { cn } from "@/lib/utils";
import {
  BACKUP_IN_FLIGHT,
  BACKUP_PERIODS,
  BACKUP_STATUSES,
  BACKUP_TYPES,
  RESTORE_IN_FLIGHT,
} from "@/lib/schemas/backup";
import { clearStuckBackup, retryBackup } from "@/lib/api/backups";
import { isRetryable } from "@/lib/backups/retry-eligibility";
import { newestBackupId, queuedApplications } from "@/lib/backups/queued";
import { apiMessage } from "@/lib/api/error-message";
import { formatBytes } from "@/lib/format/bytes";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { BackupsCards } from "@/components/backups/backups-cards";
import { FacetSelect } from "@/components/data-table/facet-select";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ListCard } from "@/components/data-table/list-card";
import { EmptyState } from "@/components/data-table/empty-state";
import { ClearFiltersButton } from "@/components/data-table/clear-filters-button";
import { RestoreDialog } from "@/components/backups/restore-dialog";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { useRestoreWatch } from "@/components/backups/restore-watch";
import { BackupsHistoryTable } from "@/components/backups/backups-history-table";
import { SubjectFilter } from "@/components/backups/subject-filter";

export function BackupsHistory({
  backups,
  counts,
  applications,
  canRestore,
  canRun,
  hasFilters,
  database = null,
  databases = [],
  pager = null,
  newest = null,
}) {
  const t = useTranslations("backups.history");
  const tr = useTranslations("backups.restore");
  const format = useFormatter();
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const params = useSearchParams();
  const { active, start } = useRestoreWatch();
  const [retrying, setRetrying] = useState(null);
  const [restoring, setRestoring] = useState(null);
  const [clearing, setClearing] = useState(null);
  const [busyId, setBusyId] = useState(null);
  const [started, setStarted] = useState({});
  const [stalled, setStalled] = useState(false);

  function askRetry(backup) {
    setRetrying(backup);
  }

  async function confirmRetry() {
    const backup = retrying;
    if (!backup) return;

    setRetrying(null);
    await retry(backup);
  }

  async function retry(backup) {
    setBusyId(backup.id);
    setStalled(false);
    try {
      await retryBackup(backup.id);
      const app = backup.application_id;
      if (app) {
        const mine = backups.filter((row) => row.application_id === app);
        setStarted((s) => ({ ...s, [app]: newestBackupId(mine) }));
      }
      await refreshAndWait();
      toast.success(t("retryStarted", { name: backup.application_name ?? "" }));
    } catch (error) {
      toast.error(apiMessage(error, t("retryFailed")));
    } finally {
      setBusyId(null);
    }
  }

  async function confirmClear() {
    const backup = clearing;
    if (!backup) return;

    setBusyId(backup.id);
    try {
      await clearStuckBackup(backup.id);
      await refreshAndWait();
      toast.success(t("clear.done", { name: backup.application_name ?? "" }));
      setClearing(null);
    } catch (error) {
      toast.error(apiMessage(error, t("clear.failed")));
    } finally {
      setBusyId(null);
    }
  }

  const queuedIds = queuedApplications(backups, started);
  const queuedNames = queuedIds
    .map(
      (id) =>
        backups.find((row) => String(row.application_id) === id)?.application_name ??
        applications.find((app) => String(app.id) === id)?.name,
    )
    .filter(Boolean);

  const statusFilter = params.get("status");
  const filterHidesRun = Boolean(statusFilter) && !BACKUP_IN_FLIGHT.includes(statusFilter);

  useEffect(() => {
    if (!queuedIds.length || stalled || filterHidesRun) return undefined;
    const id = setTimeout(() => setStalled(true), 3 * 60 * 1000);
    return () => clearTimeout(id);
  }, [queuedIds.length, stalled, filterHidesRun]);

  const restoreInFlight = RESTORE_IN_FLIGHT.includes(active?.status);

  const listProps = {
    backups,
    siteHeader: database ? t("subject.database") : undefined,
    canRestore,
    canDelete: canRestore,
    onDeleted: () => {},
    canRun,
    onRestore: setRestoring,
    onRetry: askRetry,
    onClear: setClearing,
    canClear: canRun,
    busyId,
    restoreInFlight,
    retryable: (backup) => isRetryable(backup, backups, newest),
    retryBlockedFor: (backup) => {
      if (restoreInFlight) return tr("blocked.alreadyRunning");

      return queuedIds.includes(String(backup.application_id)) ||
        backups.some(
          (row) =>
            row.application_id === backup.application_id &&
            BACKUP_IN_FLIGHT.includes(row.status),
        )
        ? t("alreadyRunning")
        : null;
    },
  };

  const busy =
    backups.some((backup) => BACKUP_IN_FLIGHT.includes(backup.status)) || restoreInFlight;

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
        options={BACKUP_STATUSES.map((value) => ({
          value,
          label: t(`statuses.${value}`),
        }))}
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
      {busy || queuedNames.length ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      {queuedNames.length ? (
        <div
          role="status"
          className={cn(
            "flex items-start gap-2.5 rounded-xl border px-4 py-3 text-sm",
            stalled ? "border-warning/30 bg-warning/10 text-foreground" : "bg-muted/40 text-muted-foreground",
          )}
        >
          {stalled ? (
            <CircleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
          ) : (
            <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-primary" />
          )}
          <span>
            {filterHidesRun
              ? t("queuedHidden", { name: queuedNames.join(", ") })
              : stalled
                ? t("queuedStalled", { name: queuedNames.join(", ") })
                : t("queuedNote", { name: queuedNames.join(", ") })}
          </span>
        </div>
      ) : null}

      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Tally icon={Archive} label={t("counts.total")} value={counts.total} tone="primary" always />
        <Tally icon={CircleCheck} label={t("counts.complete")} value={counts.verified} tone="success" />
        <Tally icon={CircleX} label={t("counts.failed")} value={counts.failed} tone="destructive" />
        <Tally icon={Loader2} label={t("counts.running")} value={counts.running} tone="primary" spin />
      </div>

      {backups.length === 0 ? (
        <ListCard toolbar={toolbar}>
          <EmptyState
            icon={Archive}
            title={hasFilters ? t("emptyFiltered.title") : t("empty.title")}
            description={hasFilters ? t("emptyFiltered.description") : t("empty.description")}
            badge={hasFilters ? "search" : null}
            action={hasFilters ? <ClearFiltersButton keys={["application", "database", "status", "period", "type", "search"]} /> : null}
          />
        </ListCard>
      ) : (
        <div className="@container/history">
        <ListCard from="c900" toolbar={toolbar} footer={pager}>
          <div className="@min-[900px]/page:hidden">
            <BackupsCards {...listProps} />
          </div>
          <div className="hidden @min-[900px]/page:block">
            <BackupsHistoryTable {...listProps} bare />
          </div>
        </ListCard>
        </div>
      )}

      <ConfirmDialog
        open={Boolean(retrying)}
        onOpenChange={(open) => !busyId && setRetrying(open ? retrying : null)}
        icon={RotateCw}
        title={t("retryConfirm.title", { name: retrying?.application_name ?? t("unknownApplication") })}
        description={
          !retrying
            ? ""
            : retrying.size_bytes
              ? t("retryConfirm.description", {
                  name: retrying.application_name ?? t("unknownApplication"),
                  size: formatBytes(retrying.size_bytes, format),
                })
              : t("retryConfirm.descriptionNoSize", {
                  name: retrying.application_name ?? t("unknownApplication"),
                })
        }
        cancelLabel={t("retryConfirm.cancel")}
        confirmLabel={t("retryConfirm.confirm")}
        pending={Boolean(retrying && busyId === retrying.id)}
        onConfirm={confirmRetry}
      />

      <ConfirmDialog
        open={Boolean(clearing)}
        onOpenChange={(open) => !busyId && setClearing(open ? clearing : null)}
        icon={CircleAlert}
        tone="warning"
        title={t("clear.title")}
        description={
          clearing
            ? t("clear.description", { name: clearing.application_name ?? t("unknownApplication") })
            : ""
        }
        cancelLabel={t("clear.cancel")}
        confirmLabel={t("clear.confirm")}
        pending={Boolean(clearing && busyId === clearing.id)}
        onConfirm={confirmClear}
      />

      <RestoreDialog
        key={restoring?.id}
        backup={restoring}
        open={Boolean(restoring)}
        onOpenChange={(next) => (next ? null : setRestoring(null))}
        onStarted={(started) => {
          setRestoring(null);
          if (started) start(started);
          router.refresh();
        }}
      />
    </div>
  );
}

const TALLY_TONE = {
  primary: "bg-primary/10 text-primary",
  success: "bg-success-soft text-success",
  destructive: "bg-destructive-soft text-destructive",
};

function Tally({ icon: Icon, label, value, tone, spin = false, always = false }) {
  const active = Number(value) > 0;
  return (
    <div className="rounded-2xl border border-border/70 bg-card px-4 py-3 shadow-e1">
      <div className="flex items-center gap-3">
        <span
          className={cn(
            "flex size-9 shrink-0 items-center justify-center rounded-xl",
            active || always ? TALLY_TONE[tone] : "bg-muted text-muted-foreground",
          )}
          aria-hidden
        >
          <Icon className={cn("size-[18px]", spin && active && "animate-spin")} />
        </span>
        <div className="min-w-0 flex-1">
          <p className="text-xs leading-tight text-muted-foreground">{label}</p>
          <p className="text-lg font-semibold tabular-nums">{value}</p>
        </div>
      </div>
    </div>
  );
}
