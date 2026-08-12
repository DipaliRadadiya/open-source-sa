"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { Archive } from "lucide-react";
import { cn } from "@/lib/utils";
import {
  BACKUP_IN_FLIGHT,
  BACKUP_PERIODS,
  BACKUP_TYPES,
  RESTORE_IN_FLIGHT,
} from "@/lib/schemas/backup";
import { retryBackup } from "@/lib/api/backups";
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
  const { active, start } = useRestoreWatch();
  const [restoring, setRestoring] = useState(null);
  const [busyId, setBusyId] = useState(null);

  // Re-runs the failed backup itself, rather than starting a fresh one from
  // the site's current state — which is what this button used to do, only
  // because no retry endpoint existed.
  async function retry(backup) {
    setBusyId(backup.id);
    try {
      await retryBackup(backup.id);
      toast.success(t("retryStarted", { name: backup.application_name ?? "" }));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("retryFailed")));
    } finally {
      setBusyId(null);
    }
  }

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
      {busy ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

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
          options={["verified", "verifying", "running", "pending", "failed"].map((value) => ({
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
