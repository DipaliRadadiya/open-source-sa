"use client";

import { useEffect, useMemo, useState } from "react";
import { usePendingKeys } from "@/hooks/use-pending-keys";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Clock, SearchX, ShieldAlert, ShieldCheck } from "lucide-react";
import { BACKUP_IN_FLIGHT, BACKUP_TYPES, RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
import { useRestoreWatch } from "@/components/backups/restore-watch";
import { runBackupNow } from "@/lib/api/backups";
import { backupStartedWithin } from "@/lib/backups/just-started";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { EmptyState } from "@/components/data-table/empty-state";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { FilterSelect } from "@/components/data-table/filter-select";
import { DestinationHealth } from "@/components/backups/destination-health";
import { CoverageTable, sortCoverage } from "@/components/backups/coverage-table";
import { CoverageCards } from "@/components/backups/coverage-cards";
import { SetupBackupsDialog } from "@/components/backups/setup-backups-dialog";

// How long a "Run backup" press keeps the page polling on its own: long enough
// for the queue to pick the job up, short enough to stop if it never starts.
const JUST_STARTED_MS = 90_000;

// Filtering is client-side: the coverage list is fully loaded during SSR.
export function CoverageCard({
  coverage,
  applications,
  destinations,
  canManage,
  databaseCounts = null,
  databasesKnown = false,
  siteTypes = null,
  backupOptions = null,
}) {
  const t = useTranslations("backups.coverage");
  const tc = useTranslations("common");
  const th = useTranslations("backups.history");
  const { refreshAndWait } = useRefresh();
  // Run backup is refused for the site a restore is writing to: a copy taken mid-restore
  // would be saved as Complete.
  const { active } = useRestoreWatch();
  const restoringId = RESTORE_IN_FLIGHT.includes(active?.status) ? active.application_id : null;
  const [setupFor, setSetupFor] = useState(null);
  const [dialogOpen, setDialogOpen] = useState(false);
  // Runs are queued, so several can start at once; each row keeps its own spinner.
  const starting = usePendingKeys();
  // Covers the gap before the row shows a new run. Cleared by a timer, not
  // `Date.now()`, to keep render pure.
  const [justStarted, setJustStarted] = useState(() => backupStartedWithin(JUST_STARTED_MS));

  const [state, setState] = useState("all");
  const [search, setSearch] = useState("");
  const [type, setType] = useState("all");

  const rows = useMemo(() => {
    const term = search.trim().toLowerCase();
    return sortCoverage(
      coverage.rows.filter((row) => {
        if (state === "protected" && row.state !== "protected") return false;
        // "Not protected" includes paused and unreadable, not just unconfigured.
        if (state === "unprotected" && row.state === "protected") return false;
        if (type !== "all" && row.target?.type !== type) return false;
        if (
          term &&
          !row.application.name.toLowerCase().includes(term) &&
          !row.application.domain.toLowerCase().includes(term)
        ) {
          return false;
        }
        return true;
      }),
    );
  }, [coverage.rows, state, search, type]);

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
      // Before the refresh: the row the server sends next still carries the
      // previous backup's finished status (see `watching`).
      setJustStarted(true);
      await refreshAndWait();
      toast.success(t("started", { name }));
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      starting.finish(applicationId);
    }
  }

  // Stop watching if the run never shows up, rather than polling indefinitely.
  useEffect(() => {
    if (!justStarted) return undefined;
    const id = setTimeout(() => setJustStarted(false), JUST_STARTED_MS);
    return () => clearTimeout(id);
  }, [justStarted]);

  const listProps = { rows, options: backupOptions, canManage, onSetUp: openSetup, onBackUpNow: backUpNow, busyIds: starting.pendingKeys, restoringId };

  // The Schedule column's timezone, only when every target agrees. An older
  // backend that sends none leaves the caption off rather than assuming UTC.
  const scheduleZones = new Set(rows.map((row) => row.target?.timezone).filter(Boolean));
  const scheduleTimezone = scheduleZones.size === 1 ? [...scheduleZones][0] : null;

  // Right after a run is accepted the row still shows the previous backup, so
  // status alone would never start the poller.
  const inFlight = coverage.rows.some((row) => BACKUP_IN_FLIGHT.includes(row.lastBackup?.status));
  const watching = inFlight || justStarted;

  return (
    <>
      {watching ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      <div className="space-y-4">
        {/* Above the coverage banner: a well-configured site can still be
            backing up to a bucket that rejects every write. */}
        <DestinationHealth
          destinations={destinations}
          inUse={coverage.rows.map((row) => row.target?.storage_destination_id).filter(Boolean)}
          lastBackups={coverage.rows
            .filter((row) => row.lastBackup)
            .map((row) => ({ ...row.lastBackup, storage_destination_id: row.target?.storage_destination_id }))}
        />

        {/* Warning (amber), not danger: unprotected sites are a risk, not a breakage. */}
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
            disabledReason={!canManage ? th("noPermission") : t("needsDestination")}
            className="w-full sm:w-auto"
          >
            <ShieldCheck className="size-4" />
            {t("setUp")}
          </Button>
        </div>

        {/* Wraps rather than squeezing: at 768 the type filter lost most of its text. */}
        <div className="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center">
          <ToggleGroup
            type="single"
            value={state}
            onValueChange={(value) => value && setState(value)}
            variant="outline"
            size="sm"
          >
            <ToggleGroupItem value="all">{t("filters.all")}</ToggleGroupItem>
            <ToggleGroupItem value="unprotected">{t("filters.unprotected")}</ToggleGroupItem>
            <ToggleGroupItem value="protected">{t("filters.protected")}</ToggleGroupItem>
          </ToggleGroup>

          <LocalSearchInput value={search} onChange={setSearch} placeholder={t("searchPlaceholder")} />

          {/* Same dropdown as History, but filtering in memory (not in the URL). */}
          <FilterSelect
            value={type}
            onChange={setType}
            allLabel={t("filters.anyType")}
            label={t("columns.type")}
            options={BACKUP_TYPES.map((value) => ({ value, label: t(`types.${value}`) }))}
            className="w-full sm:w-auto sm:min-w-36 sm:shrink-0"
          />

          <span className="text-xs tabular-nums text-muted-foreground sm:ml-auto">
            {t("showing", { shown: rows.length, total: coverage.total })}
          </span>
          <RefreshButton />
        </div>

        {/* The filters are not in the URL, so an emptied list must say so. */}
        {rows.length === 0 ? (
          <EmptyState
            icon={SearchX}
            title={t("noMatches")}
            action={
              <Button
                variant="outline"
                onClick={() => {
                  setSearch("");
                  setState("all");
                  setType("all");
                }}
              >
                {tc("clearFilters")}
              </Button>
            }
          />
        ) : (
          <>
            {/* Stated once here: in the column header it forced horizontal scroll. */}
            {scheduleTimezone ? (
              <p className="mb-2 flex items-center gap-1.5 text-xs text-muted-foreground">
                <Clock className="size-3.5 shrink-0" />
                {t("timesShownIn", { timezone: scheduleTimezone })}
              </p>
            ) : null}
            {/* Container query, not viewport: the table needs ~1,180px in the
                widest locale. Cards until it fits. */}
            <div className="@container">
              <div className="@min-[1180px]:hidden">
                <CoverageCards {...listProps} />
              </div>
              <div className="hidden @min-[1180px]:block">
                <CoverageTable {...listProps} />
              </div>
            </div>
          </>
        )}
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
