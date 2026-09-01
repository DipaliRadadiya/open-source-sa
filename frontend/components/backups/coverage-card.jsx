"use client";

import { useEffect, useMemo, useState } from "react";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { SearchX, ShieldAlert, ShieldCheck } from "lucide-react";
import { BACKUP_IN_FLIGHT, BACKUP_TYPES } from "@/lib/schemas/backup";
import { runBackupNow } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { LocalSearchInput } from "@/components/data-table/local-search-input";
import { EmptyState } from "@/components/data-table/empty-state";
import { ToggleGroup, ToggleGroupItem } from "@/components/ui/toggle-group";
import { FilterSelect } from "@/components/data-table/filter-select";
import { DestinationHealth } from "@/components/backups/destination-health";
import { CoverageTable, sortCoverage } from "@/components/backups/coverage-table";
import { CoverageCards } from "@/components/backups/coverage-cards";
import { SetupBackupsDialog } from "@/components/backups/setup-backups-dialog";

// How long a press of "Run backup" keeps the page watching on its own. Long
// enough for a queue to pick the job up and the row to start reporting it;
// short enough that a job which never starts stops polling on its own.
const JUST_STARTED_MS = 90_000;

/**
 * Which sites are protected, and which are not.
 *
 * Filtering is client-side on purpose: the coverage list is already fully
 * loaded (one call per application during SSR), so a round trip to narrow ten
 * rows would be slower and would lose the instant feel.
 */
export function CoverageCard({ coverage, applications, destinations, canManage }) {
  const t = useTranslations("backups.coverage");
  const tc = useTranslations("common");
  const router = useRouter();
  const [setupFor, setSetupFor] = useState(null);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [busyId, setBusyId] = useState(null);
  // Set when "Run backup" is pressed, so the poller can cover the gap between
  // accepting the run and the row showing it. A timer clears it rather than a
  // comparison against `Date.now()`, which would make the render impure.
  const [justStarted, setJustStarted] = useState(false);

  const [state, setState] = useState("all");
  const [search, setSearch] = useState("");
  const [type, setType] = useState("all");

  const rows = useMemo(() => {
    const term = search.trim().toLowerCase();
    return sortCoverage(
      coverage.rows.filter((row) => {
        if (state === "protected" && row.state !== "protected") return false;
        // "Not protected" means anything that is not actually backing up —
        // paused and unreadable belong here, not with the healthy sites.
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
    setBusyId(applicationId);
    try {
      await runBackupNow(applicationId);
      toast.success(t("started", { name }));
      // Before the refresh, not after. The run is queued, so the row the
      // server is about to send still carries the PREVIOUS backup's finished
      // status — see `watching` below.
      setJustStarted(true);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setBusyId(null);
    }
  }

  // Stop watching on our own if the run never shows up — a job that was
  // accepted and then never ran must not poll for as long as the tab is open.
  useEffect(() => {
    if (!justStarted) return undefined;
    const id = setTimeout(() => setJustStarted(false), JUST_STARTED_MS);
    return () => clearTimeout(id);
  }, [justStarted]);

  const listProps = { rows, canManage, onSetUp: openSetup, onBackUpNow: backUpNow, busyId };

  // Pressing "Run backup" used to leave the row unchanged until someone
  // reloaded. While any site's newest run is still being written, re-run the
  // server component so the status dot and the last-run time keep up.
  //
  // The status alone is not enough to start watching. `router.refresh()` fires
  // the instant the API accepts the run, and at that moment the newest backup
  // on the row is still the *last* one, finished — so nothing looked in
  // flight, the poller never mounted, and a run that completed four seconds
  // later left the row reading the old timestamp until someone reloaded by
  // hand. Pressing the button is itself evidence that work has begun, so it
  // opens a window in which we keep watching regardless of what the row says.
  const inFlight = coverage.rows.some((row) => BACKUP_IN_FLIGHT.includes(row.lastBackup?.status));
  const watching = inFlight || justStarted;

  return (
    <>
      {watching ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      <div className="space-y-4">
        {/* Above the coverage banner: a site can be perfectly configured and
            still be backing up to a bucket that rejects every write, which no
            amount of green in the table below would reveal. */}
        <DestinationHealth
          destinations={destinations}
          inUse={coverage.rows.map((row) => row.target?.storage_destination_id).filter(Boolean)}
        />

        {/* The state of the server, said properly. A thin one-line bar made the
            most important sentence on the page look like a dismissible
            notification — this is a headline, a consequence, and the one action
            that fixes it. */}
        {/* Warning, not danger. Red says "something is broken right now";
            unprotected sites are a risk you should fix, which is amber. It
            still leads the page — it just stops shouting over the table it is
            meant to introduce. */}
        <div
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

          {canManage ? (
            <Button
              onClick={() => openSetup(null)}
              disabled={destinations.length === 0}
              disabledReason={t("needsDestination")}
              className="w-full sm:w-auto"
            >
              <ShieldCheck className="size-4" />
              {t("setUp")}
            </Button>
          ) : null}
        </div>

        <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
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

          {/* The same dropdown History uses. It filters in memory here — the
              coverage list is already loaded, so putting this in the URL would
              buy shareability at the cost of an API call per change. */}
          <FilterSelect
            value={type}
            onChange={setType}
            allLabel={t("filters.anyType")}
            options={BACKUP_TYPES.map((value) => ({ value, label: t(`types.${value}`) }))}
            className="w-full sm:w-48"
          />

          <span className="text-xs tabular-nums text-muted-foreground sm:ml-auto">
            {t("showing", { shown: rows.length, total: coverage.total })}
          </span>
        </div>

        {/* Three filters live here and none of them is in the URL, so a
            narrowed-to-nothing list showed an empty table with no hint of
            which one did it. */}
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
            <div className="lg:hidden">
              <CoverageCards {...listProps} />
            </div>
            <div className="hidden lg:block">
              <CoverageTable {...listProps} />
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
      />
    </>
  );
}
