"use client";

import { useEffect, useState } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Archive, CircleAlert, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import {
  BACKUP_IN_FLIGHT,
  BACKUP_PERIODS,
  BACKUP_STATUSES,
  BACKUP_TYPES,
  RESTORE_IN_FLIGHT,
} from "@/lib/schemas/backup";
import { retryBackup } from "@/lib/api/backups";
import { newestBackupId, queuedApplications } from "@/lib/backups/queued";
import { apiMessage } from "@/lib/api/error-message";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { Card, CardContent } from "@/components/ui/card";
import { BackupsCards } from "@/components/backups/backups-cards";
import { FacetSelect } from "@/components/data-table/facet-select";
import { EmptyState } from "@/components/data-table/empty-state";
import { RestoreDialog } from "@/components/backups/restore-dialog";
import { useRestoreWatch } from "@/components/backups/restore-watch";
import { BackupsHistoryTable } from "@/components/backups/backups-history-table";

/**
 * Every backup that has run, across every application.
 *
 * Server-paginated: this list grows without bound — one row per application
 * per run, forever.
 *
 * Restore is offered here and on the application page, but through one dialog
 * and one permission (`backup,manage`, separate from `app_backup`) — two doors
 * to the same guardrails rather than two implementations of them.
 */
export function BackupsHistory({
  backups,
  counts,
  applications,
  canRestore,
  canRun,
  hasFilters,
}) {
  const t = useTranslations("backups.history");
  const router = useRouter();
  const params = useSearchParams();
  const { active, start } = useRestoreWatch();
  const [restoring, setRestoring] = useState(null);
  const [busyId, setBusyId] = useState(null);
  // Runs started here: application id → that site's newest backup id at the
  // click. Per site, not one flag — this list spans every application, and one
  // site's run appearing must not end another site's wait.
  //
  // Needed because `POST /backups/{id}/retry` dispatches the same RunBackup job
  // "Back up now" does and answers 202 with the *target*. No row exists until a
  // worker starts, so the refresh after the click returned an unchanged list,
  // nothing looked in flight, and the table never polled. The toast was the
  // only evidence, and it went away.
  const [started, setStarted] = useState({});
  const [stalled, setStalled] = useState(false);

  // Retry is a label, not a separate operation: the endpoint checks the row is
  // failed and then re-runs the target, creating a NEW row rather than reviving
  // this one. So what we wait for is "a newer run for this site", not "this row
  // changed".
  async function retry(backup) {
    setBusyId(backup.id);
    setStalled(false);
    try {
      await retryBackup(backup.id);
      toast.success(t("retryStarted", { name: backup.application_name ?? "" }));
      const app = backup.application_id;
      if (app) {
        const mine = backups.filter((row) => row.application_id === app);
        setStarted((s) => ({ ...s, [app]: newestBackupId(mine) }));
      }
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("retryFailed")));
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

  // A status filter that excludes in-flight runs means the new row genuinely
  // cannot show up in *this* list, however long we wait. Saying "it appears
  // here shortly" would then be a promise the filter forbids.
  const statusFilter = params.get("status");
  const filterHidesRun = Boolean(statusFilter) && !BACKUP_IN_FLIGHT.includes(statusFilter);

  useEffect(() => {
    if (!queuedIds.length || stalled || filterHidesRun) return undefined;
    const id = setTimeout(() => setStalled(true), 3 * 60 * 1000);
    return () => clearTimeout(id);
  }, [queuedIds.length, stalled, filterHidesRun]);

  // `restoreBlocker` has always known how to refuse a second restore while one
  // is running, but nothing ever passed the flag — so the guard was dead code
  // and its message unreachable. Two restores over one site is the one thing
  // here nobody can undo, and finding out from a 422 after typing your own
  // domain is the wrong moment.
  const restoreInFlight = RESTORE_IN_FLIGHT.includes(active?.status);

  const listProps = {
    backups,
    canRestore,
    canRun,
    onRestore: setRestoring,
    onRetry: retry,
    busyId,
    restoreInFlight,
    // Per site: a run under way for THIS site blocks its rows, and leaves every
    // other site's Retry alone. The endpoint refuses a second run per target
    // with a 422, so the button should refuse it first and say why.
    retryBlockedFor: (backup) =>
      queuedIds.includes(String(backup.application_id)) ||
      backups.some(
        (row) =>
          row.application_id === backup.application_id &&
          BACKUP_IN_FLIGHT.includes(row.status),
      )
        ? t("alreadyRunning")
        : null,
  };

  // A backup writes for minutes. Without this the row says "Backing up" until
  // someone thinks to reload, which reads as a stuck job rather than a running
  // one. Only while something is actually in flight.
  //
  // A restore counts too: it takes a safety copy on the way past, so a row this
  // list has never shown appears partway through — and the restore's own
  // polling refreshes the banner, not the table underneath it.
  const busy =
    backups.some((backup) => BACKUP_IN_FLIGHT.includes(backup.status)) || restoreInFlight;

  return (
    <div className="space-y-4">
      {busy || queuedNames.length ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      {/* The gap a toast cannot cover: the run has been accepted but has no row
          yet, so every visible thing on this page is unchanged. Named by site,
          because on this list "a backup" is not enough to know whose. */}
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

      {/* Counts come from the API, not from this page's rows — "3 failed" has
          to mean three in total, not three on page one of forty. */}
      <div className="grid grid-cols-2 gap-3 sm:grid-cols-4">
        <Tally label={t("counts.total")} value={counts.total} />
        <Tally label={t("counts.complete")} value={counts.verified} tone="text-success" dot="bg-success" />
        <Tally label={t("counts.failed")} value={counts.failed} tone="text-destructive" dot="bg-destructive" />
        <Tally label={t("counts.running")} value={counts.running} tone="text-primary" dot="bg-primary" />
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
        {/* A searchable picker rather than a plain select: `/backups` has no
            text search, but "find the site" is the search people actually
            mean, and the combobox filters as you type. */}
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
          options={BACKUP_STATUSES.map((value) => ({
            value,
            label: t(`statuses.${value}`),
          }))}
          className="w-full sm:w-44"
        />
        {/* Presets, not a calendar. "What ran last week" is the question
            people actually have, and a two-field date picker is a heavier
            control than that question deserves. */}
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

      {backups.length === 0 ? (
        <EmptyState
          icon={Archive}
          title={hasFilters ? t("emptyFiltered.title") : t("empty.title")}
          description={hasFilters ? t("emptyFiltered.description") : t("empty.description")}
        />
      ) : (
        <>
          <div className="lg:hidden">
            <BackupsCards {...listProps} />
          </div>
          <div className="hidden lg:block">
            <BackupsHistoryTable {...listProps} />
          </div>
        </>
      )}

      <RestoreDialog
        key={restoring?.id}
        backup={restoring}
        open={Boolean(restoring)}
        onOpenChange={(next) => (next ? null : setRestoring(null))}
        onStarted={(started) => {
          setRestoring(null);
          // Hand it straight to the banner. Relying on router.refresh() alone
          // meant the dialog closed onto an unchanged list, and the only way to
          // see that anything was happening was to reload.
          if (started) start(started);
          router.refresh();
        }}
      />
    </div>
  );
}

/**
 * One line per figure: dot, number, label.
 *
 * Stacked label-over-number cards were mostly whitespace, and the label carried
 * the only colour. The dot matches the status badge in the table below, so the
 * summary and the rows use one colour language.
 */
function Tally({ label, value, tone, dot }) {
  return (
    <Card className="gap-0 py-0 shadow-sm">
      <CardContent className="flex items-center gap-2.5 px-3.5 py-2.5">
        <span
          className={cn("size-2 shrink-0 rounded-full", dot ?? "bg-muted-foreground/40")}
          aria-hidden
        />
        <span className={cn("text-lg font-semibold leading-none tabular-nums", tone)}>{value}</span>
        <span className="truncate text-xs text-muted-foreground">{label}</span>
      </CardContent>
    </Card>
  );
}
