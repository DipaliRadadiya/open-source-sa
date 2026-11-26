"use client";

import { useEffect, useState } from "react";
import { useSearchParams } from "next/navigation";
import { usePendingKeys } from "@/hooks/use-pending-keys";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { SearchX, ShieldAlert, ShieldCheck, Archive } from "lucide-react";
import { BACKUP_IN_FLIGHT, BACKUP_TYPES, RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
import { useRestoreWatch } from "@/components/backups/restore-watch";
import { runBackupNow } from "@/lib/api/backups";
import { backupStartedWithin } from "@/lib/backups/just-started";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { SearchInput } from "@/components/data-table/search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { EmptyState } from "@/components/data-table/empty-state";
import { ListCard } from "@/components/data-table/list-card";
import { FacetSelect } from "@/components/data-table/facet-select";
import { ClearFiltersButton } from "@/components/data-table/clear-filters-button";
import { DestinationHealth } from "@/components/backups/destination-health";
import { CoverageTable } from "@/components/backups/coverage-table";
import { CoverageCards } from "@/components/backups/coverage-cards";
import { SetupBackupsDialog } from "@/components/backups/setup-backups-dialog";
import { DENIED } from "@/components/ui/reason-tooltip";

const JUST_STARTED_MS = 90_000;

const FILTER_KEYS = ["search", "state", "type"];

export function CoverageCard({
  coverage,
  applications,
  destinationUsage = { inUse: [], lastBackups: [] },
  pager = null,
  destinations,
  canManage,
  databaseCounts = null,
  databasesKnown = false,
  siteTypes = null,
  backupOptions = null,
}) {
  const t = useTranslations("backups.coverage");
  const th = useTranslations("backups.history");
  const { refreshAndWait } = useRefresh();
  // Blocked during a restore: a copy taken mid-restore would be saved as complete.
  const { active } = useRestoreWatch();
  const restoringId = RESTORE_IN_FLIGHT.includes(active?.status) ? active.application_id : null;
  const [setupFor, setSetupFor] = useState(null);
  const [dialogOpen, setDialogOpen] = useState(false);
  const starting = usePendingKeys();
  const [justStarted, setJustStarted] = useState(() => backupStartedWithin(JUST_STARTED_MS));

  const searchParams = useSearchParams();
  const hasFilters = FILTER_KEYS.some((key) => searchParams.get(key));
  const rows = coverage.rows;

  const exposed = coverage.total - coverage.protected;
  const allCovered = coverage.total > 0 && exposed === 0;

  function openSetup(applicationId) {
    setSetupFor(applicationId);
    setDialogOpen(true);
  }

  async function backUpNow(applicationId, name) {
    if (starting.isPending(applicationId)) return;
    starting.start(applicationId);
    try {
      await runBackupNow(applicationId);
      setJustStarted(true);
      await refreshAndWait();
      toast.success(t("started", { name }));
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      starting.finish(applicationId);
    }
  }

  useEffect(() => {
    if (!justStarted) return undefined;
    const id = setTimeout(() => setJustStarted(false), JUST_STARTED_MS);
    return () => clearTimeout(id);
  }, [justStarted]);

  const scheduleZones = new Set(rows.map((row) => row.target?.timezone).filter(Boolean));
  const scheduleTimezone = scheduleZones.size === 1 ? [...scheduleZones][0] : null;

  const listProps = { rows, options: backupOptions, canManage, onSetUp: openSetup, onBackUpNow: backUpNow, busyIds: starting.pendingKeys, restoringId, scheduleTimezone };

  // Right after starting, the row still shows the previous backup.
  const inFlight = coverage.rows.some((row) => BACKUP_IN_FLIGHT.includes(row.lastBackup?.status));
  const watching = inFlight || justStarted;

  const toolbar = (
    <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
      <SearchInput placeholder={t("searchPlaceholder")} />
      <FacetSelect
        paramKey="state"
        allLabel={th("allStatuses")}
        label={t("columns.status")}
        options={[
          { value: "unprotected", label: t("filters.unprotected") },
          { value: "protected", label: t("filters.protected") },
          { value: "failing", label: t("status.failing") },
          { value: "paused", label: t("status.paused") },
        ]}
        className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
      />
      <div className="flex items-center gap-3 sm:contents">
        <FacetSelect
          paramKey="type"
          allLabel={t("filters.anyType")}
          label={t("columns.type")}
          options={BACKUP_TYPES.map((value) => ({ value, label: t(`types.${value}`) }))}
          className="min-w-0 flex-1 sm:w-auto sm:min-w-36 sm:flex-none sm:shrink-0"
        />
        <div className="sm:ml-auto">
          <RefreshButton />
        </div>
      </div>
    </div>
  );

  const footer = pager;

  return (
    <>
      {watching ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      <div className="space-y-6">
        <DestinationHealth
          destinations={destinations}
          inUse={destinationUsage.inUse}
          lastBackups={destinationUsage.lastBackups}
        />

        <div
          data-slot="notice"
          className={`flex flex-col items-start gap-3 rounded-xl border p-4 sm:flex-row sm:items-center sm:gap-4 ${
            allCovered ? "border-success/30 bg-success/5" : "border-warning/30 bg-warning/5"
          }`}
        >
          <span
            className={`flex size-9 shrink-0 items-center justify-center rounded-lg ${
              allCovered ? "bg-success/10 text-success" : "bg-warning/15 text-warning"
            }`}
          >
            {allCovered ? <ShieldCheck className="size-5" /> : <ShieldAlert className="size-5" />}
          </span>

          <div className="min-w-0 flex-1">
            <p className="font-semibold tracking-tight">
              {allCovered ? t("allCovered") : t("exposed", { count: exposed })}
            </p>
            <p className="text-sm text-muted-foreground">
              {allCovered ? t("allCoveredHint") : t("exposedHint")}
            </p>
          </div>

          <Button
            onClick={() => openSetup(null)}
            disabled={!canManage || destinations.length === 0}
            disabledReason={!canManage ? DENIED : t("needsDestination")}
            className="w-full sm:w-auto"
          >
            <ShieldCheck className="size-4" />
            {t("setUp")}
          </Button>
        </div>

        <div className="@container/coverage">
          {rows.length === 0 ? (
            <ListCard toolbar={toolbar}>
              <EmptyState
                icon={SearchX}
                subject={Archive}
                title={t("noMatches")}
                action={hasFilters ? <ClearFiltersButton keys={FILTER_KEYS} /> : null}
              />
            </ListCard>
          ) : (
            <ListCard from="c900" toolbar={toolbar} footer={footer}>
              <div className="@min-[900px]/page:hidden">
                <CoverageCards {...listProps} />
              </div>
              <div className="hidden @min-[900px]/page:block">
                <CoverageTable {...listProps} bare />
              </div>
            </ListCard>
          )}
        </div>
      </div>

      <SetupBackupsDialog
        open={dialogOpen}
        onOpenChange={setDialogOpen}
        applications={applications}
        destinations={destinations}
        applicationId={setupFor}
        databaseCounts={databaseCounts}
        databasesKnown={databasesKnown}
        siteTypes={siteTypes}
        options={backupOptions}
        onStarted={() => setJustStarted(true)}
      />
    </>
  );
}
