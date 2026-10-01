"use client";

import { useEffect, useState } from "react";
import Link from "@/components/ui/app-link";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  CircleAlert,
  CircleSlash,
  Database,
  History,
  Loader2,
  PauseCircle,
  Pencil,
  PlayCircle,
  PowerOff,
  ShieldAlert,
  ShieldCheck,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { BACKUP_IN_FLIGHT, RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
import { isBackupQueued, newestBackupId } from "@/lib/backups/queued";
import { scheduleWhen } from "@/lib/backups/schedule-time";
import { clearStuckBackup, retryBackup, runBackupNow } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { BackupsCards } from "@/components/backups/backups-cards";
import { BackupsHistoryTable } from "@/components/backups/backups-history-table";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ActiveRestore } from "@/components/backups/active-restore";
import { DestinationHealth } from "@/components/backups/destination-health";
import { DatabaseCardActions } from "@/components/applications/database-card-actions";
import { RestoreDialog } from "@/components/backups/restore-dialog";
import { SetupBackupsDialog } from "@/components/backups/setup-backups-dialog";
import { TurnOffBackupsDialog } from "@/components/applications/backups/turn-off-backups-dialog";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { hasNoDatabase } from "@/lib/backups/database-availability";

// How long "queued" is shown before reporting that the worker has not taken it.
const QUEUE_STALLED_MS = 3 * 60 * 1000;

export function BackupsPanel({
  application,
  target,
  destinations,
  backups,
  total = 0,
  activeRestore = null,
  canManage,
  canRestore,
  // `backup` manage: removing the schedule can delete every archive, so the
  // API requires the server-level permission, not `app_backup`.
  canTurnOff = false,
  databaseCounts = null,
  databasesKnown = false,
  // Only supplied when the reader can manage databases.
  siteDatabases = [],
  // False when the database read failed: an empty list then means unknown,
  // and the warning below must not fire.
  siteDatabasesKnown = true,
  unattachedDatabases = [],
  engines = [],
  needsDatabase = false,
  siteTypes = null,
  canManageDatabases = false,
  // The history request failed (empty is not "never backed up").
  backupsFailed = false,
  // 403: the history needs the server-level Backups permission, which a
  // site-only role may lack.
  backupsForbidden = false,
  // `GET /backup-targets/options`; null when it could not be read.
  backupOptions = null,
}) {
  const t = useTranslations("backups.application");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [running, setRunning] = useState(false);
  const [retryingId, setRetryingId] = useState(null);
  const [clearing, setClearing] = useState(null);
  const [restoring, setRestoring] = useState(null);
  const [editing, setEditing] = useState(false);
  const [turningOff, setTurningOff] = useState(false);
  // Replaced when a restore starts here so progress shows without a round trip.
  const [restore, setRestore] = useState(activeRestore);
  // Only a restore started here should scroll the viewport.
  const [restoreStartedHere, setRestoreStartedHere] = useState(false);
  // Latest status reported by the restore banner's polling, so the rest of the
  // page can block Restore and Back up now while the site is being overwritten.
  const [restoreStatus, setRestoreStatus] = useState(null);
  const restoreRunning = RESTORE_IN_FLIGHT.includes(restoreStatus ?? restore?.status);
  // Newest backup id when a run was started here. The POST returns 202 before the row exists,
  // so this keeps the queued state and polling alive until it appears.
  const [queuedAfter, setQueuedAfter] = useState(null);
  const [stalled, setStalled] = useState(false);

  // Poll only while a run is in flight.
  const busy = backups.some((backup) => BACKUP_IN_FLIGHT.includes(backup.status));

  // The wait ends as soon as a backup newer than the starting one appears.
  const newestId = newestBackupId(backups);
  const queued = isBackupQueued(backups, queuedAfter);

  // Clear once the wait is over, or deleting the newest backup shows "queued" again.
  // Done during render to avoid painting the wrong state for a frame.
  if (queuedAfter !== null && !queued) setQueuedAfter(null);

  // Report a stalled queue instead of silently reverting to "nothing happened".
  useEffect(() => {
    if (!queued || stalled) return undefined;
    const id = setTimeout(() => setStalled(true), QUEUE_STALLED_MS);
    return () => clearTimeout(id);
  }, [queued, stalled]);

  // Retry dispatches a fresh `RunBackup` (a new row, not this one), so it
  // needs the same queue-window bookkeeping as "Back up now".
  async function retry(backup) {
    setRetryingId(backup.id);
    setStalled(false);
    try {
      await retryBackup(backup.id);
      setQueuedAfter(newestId);
      await refreshAndWait();
      toast.success(t("retryStarted"));
    } catch (error) {
      toast.error(apiMessage(error, t("retryFailed")));
    } finally {
      setRetryingId(null);
    }
  }

  async function confirmClear() {
    const backup = clearing;
    if (!backup) return;

    setRetryingId(backup.id);
    try {
      await clearStuckBackup(backup.id);
      await refreshAndWait();
      toast.success(t("clear.done"));
      setClearing(null);
    } catch (error) {
      toast.error(apiMessage(error, t("clear.failed")));
    } finally {
      setRetryingId(null);
    }
  }

  async function backUpNow() {
    setRunning(true);
    setStalled(false);
    try {
      await runBackupNow(application.id);
  // Remember where the list stood so the queued state ends when the row appears.
      setQueuedAfter(newestId);
      await refreshAndWait();
      toast.success(t("started"));
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className="space-y-6">
      {busy || queued ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      {/* First: a restore is rewriting this site's files and database now. */}
      {restore ? (
        <ActiveRestore
          key={restore.id}
          restore={restore}
          onStatusChange={setRestoreStatus}
          applicationDomain={application.domain}
          restoredSafetyCopy={Boolean(restore.restored_safety_copy)}
          scrollIntoView={restoreStartedHere}
        />
      ) : null}

      {/* On the page rather than in the settings dialog: an action there would
          open a second dialog over a half-filled form. */}
      {canManageDatabases && needsDatabase && siteDatabasesKnown && siteDatabases.length === 0 ? (
        <div className="flex flex-col gap-3 rounded-xl border border-warning/40 bg-warning/10 px-4 py-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between">
          <div className="flex items-start gap-2.5">
            <Database className="mt-0.5 size-4 shrink-0 text-warning" />
            <div className="space-y-0.5">
              <p className="text-sm font-medium">{t("noDatabase.title")}</p>
              <p className="text-xs text-muted-foreground">{t("noDatabase.description")}</p>
            </div>
          </div>
          <div className="shrink-0">
            <DatabaseCardActions
              application={application}
              databases={siteDatabases}
              unattached={unattachedDatabases}
              engines={engines}
              warn
            />
          </div>
        </div>
      ) : null}

      <DestinationHealth
        destinations={destinations}
        inUse={target?.storage_destination_id ? [target.storage_destination_id] : []}
      />

      <ProtectionCard
        target={target}
        options={backupOptions}
        // The newest run: `last_run_at` is unset when a run crashes.
        lastBackup={backups[0] ?? null}
        // Otherwise a failed history read plus no `last_run_at` renders as
        // "No backup has run yet".
        lastBackupUnknown={backupsFailed}
        // Known to hold nothing (not "could not ask"). `last_run_at` survives a
        // deleted history, and in-flight runs are not kept backups.
        noneKept={!backupsFailed && total - backups.filter((b) => BACKUP_IN_FLIGHT.includes(b.status)).length === 0}
        canManage={canManage}
        // Spinner only for a run this page is waiting on: a listed in-flight row can stay
        // "running" forever if its worker died.
        running={running || queued}
        blockedReason={
          restoreRunning
            ? t("restoreRunning")
            : !running && !queued && busy
              ? t("alreadyRunning")
              : null
        }
        onBackUpNow={backUpNow}
        onEdit={() => setEditing(true)}
        canTurnOff={canTurnOff}
        // The API refuses while a run is in flight: its archive would belong
        // to a schedule that no longer exists.
        turnOffBlockedReason={
          restoreRunning ? t("restoreRunning") : running || queued || busy ? t("turnOff.running") : null
        }
        onTurnOff={() => setTurningOff(true)}
      />

      <RecentBackups
        backups={backups}
        total={total}
        failed={backupsFailed}
        forbidden={backupsForbidden}
        applicationId={application.id}
        canRestore={canRestore}
        canManage={canManage}
        onRestore={setRestoring}
        onRetry={retry}
        onClear={setClearing}
        busyId={retryingId}
        queued={queued}
        stalled={stalled}
        // The endpoint answers 422 while a run is under way; say so on the
        // button. All rows belong to this site, so the reason is shared.
        retryBlockedReason={
          restoreRunning ? t("restoreRunning") : queued || busy ? t("alreadyRunning") : null
        }
        restoreInFlight={restoreRunning}
      />

      {/* Edit mode when a target exists; `applicationId` is fixed, so the site
          picker never renders. */}
      <SetupBackupsDialog
        open={editing}
        onOpenChange={setEditing}
        applicationId={application.id}
        destinations={destinations}
        applicationName={application.name}
        target={target}
        databaseCounts={databaseCounts}
        databasesKnown={databasesKnown}
        siteTypes={siteTypes}
        siteType={application.site_type}
        options={backupOptions}
        // Starts the same queue window as the card's button.
        onStarted={() => {
          setStalled(false);
          setQueuedAfter(newestId);
        }}
      />

      {/* Kept while open: a successful turn-off refreshes to no target, and
          the dialog must outlive that to close and confirm. */}
      {target || turningOff ? (
        <TurnOffBackupsDialog
          open={turningOff}
          onOpenChange={setTurningOff}
          application={application}
          target={target}
          count={backupsFailed ? null : total}
          // Every destination this site's archives sit in, not just the current one.
          destinationNames={[
            ...new Set(
              [target?.storage_destination_name, ...(backups ?? []).map((backup) => backup.storage_destination_name)].filter(Boolean),
            ),
          ]}
        />
      ) : null}

      <ConfirmDialog
        open={Boolean(clearing)}
        onOpenChange={(open) => !retryingId && setClearing(open ? clearing : null)}
        icon={CircleAlert}
        tone="warning"
        title={t("clear.title")}
        description={clearing ? t("clear.description") : ""}
        cancelLabel={t("clear.cancel")}
        confirmLabel={t("clear.confirm")}
        pending={Boolean(clearing && retryingId === clearing.id)}
        onConfirm={confirmClear}
      />

      <RestoreDialog
        key={restoring?.id}
        backup={
          restoring
            ? {
                ...restoring,
                application_domain: application.domain,
                application_name: application.name,
                // A type that never has a database: its archives hold files only,
                // so "Database only" restores nothing.
                files_only:
                  Boolean(siteTypes?.length) &&
                  !needsDatabase &&
                  hasNoDatabase(databaseCounts, databasesKnown, application.id) === true,
              }
            : null
        }
        open={Boolean(restoring)}
        onOpenChange={(next) => (next ? null : setRestoring(null))}
        onStarted={(started) => {
          setRestoring(null);
          if (started) {
            setRestore(started);
            setRestoreStatus(null);
            setRestoreStartedHere(true);
          }
          router.refresh();
        }}
      />
    </div>
  );
}

const STATE = {
  protected: { icon: ShieldCheck, tone: "bg-success/10 text-success", ring: "border-success/30" },
  empty: { icon: ShieldAlert, tone: "bg-warning/15 text-warning", ring: "border-warning/30" },
  paused: { icon: PauseCircle, tone: "bg-warning/15 text-warning", ring: "border-warning/30" },
  unprotected: {
    icon: CircleSlash,
    tone: "bg-destructive/10 text-destructive",
    ring: "border-destructive/30",
  },
};

function stateOf(target, noneKept) {
  if (!target) return "unprotected";
  // A disabled or manual target runs on no schedule and backs up nothing, so
  // it is never reported as protected.
  if (!target.enabled || target.frequency === "manual") return "paused";
  // Scheduled, but nothing to restore from.
  if (noneKept) return "empty";
  return "protected";
}

function ProtectionCard({ target, options = null, lastBackup, lastBackupUnknown = false, noneKept = false, canManage, running, blockedReason, onBackUpNow, onEdit, canTurnOff = false, turnOffBlockedReason = null, onTurnOff }) {
  const t = useTranslations("backups.application");
  const tHistory = useTranslations("backups.history");
  const state = stateOf(target, noneKept);
  const { icon: Icon, tone, ring } = STATE[state];
  // The stored time is 24-hour; format it so the card matches the locale-based picker.
  const format = useFormatter();

  // The zone is shown with the time, otherwise it reads as the reader's own clock.
  const when = scheduleWhen(target, options, format);
  const frequencyTitle = target?.frequency_title ?? target?.frequency;
  const scheduleKey = when?.minute ? "summary.howOftenMinute" : "summary.howOftenAt";
  const schedule = when
    ? t(target.timezone ? `${scheduleKey}Zone` : scheduleKey, {
        frequency: frequencyTitle,
        time: when.time ?? "",
        minute: when.minute ?? "",
        timezone: target.timezone ?? "",
      })
    : frequencyTitle;

  const facts = target
    ? [
        { label: t("summary.what"), value: target.type_title ?? target.type },
        {
          label: t("summary.howOften"),
          // Reader's clock format, NOT their timezone: the hour is the app clock, as stored.
          // Converting would name an hour the scheduler never runs at.
          value: schedule,
        },
        {
          label: t("summary.keeps"),
          // Never a dash: zero means keep every backup (`RetentionEnforcer` skips `keep <= 0`).
          value:
            target.retention_count === null || target.retention_count === undefined
              ? t("summary.keepsNotApplicable")
              : Number(target.retention_count) <= 0
                ? t("summary.keepsEverything")
                : t("summary.keepsValue", { count: target.retention_count }),
        },
        {
          label: t("summary.where"),
          value: target.storage_destination_name ?? t("summary.noStorage"),
        },
        {
          label: t("summary.lastBackup"),
          // Falls back to the run itself: a crashed run never writes last_run_at.
          value: noneKept
            ? target.last_run_at
              ? t("noneKept")
              : t("neverRun")
            : (target.last_run_at_human ??
              lastBackup?.created_at_human ??
              (lastBackupUnknown ? "—" : t("neverRun"))),
        },
        {
          label: t("summary.nextBackup"),
          // `is_due` outranks the timestamp: a new target runs on the next
          // scheduler tick, not at the next scheduled slot.
          value: target.is_due
            ? t("summary.runsShortly")
            : (target.next_run_at_human ?? t("summary.noNextRun")),
        },
      ]
    : [];

  const excludes = (target?.file_excludes?.length ?? 0) + (target?.database_excludes?.length ?? 0);
  const noPermission = tHistory("noPermission");

  return (
    <Card className={cn("gap-0 overflow-hidden py-0 shadow-sm", ring)}>
      <div className="flex flex-col items-start gap-3 border-b px-5 py-4 sm:flex-row sm:items-center">
        <span className={cn("flex size-10 shrink-0 items-center justify-center rounded-lg", tone)}>
          <Icon className="size-5" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="font-semibold tracking-tight">{t(`state.${state}.title`)}</p>
          {/* `state.protected.body` requires {schedule} and {destination}. */}
          <p className="text-sm text-muted-foreground">
            {state === "protected"
              ? t("state.protected.body", {
                  schedule,
                  destination: target.storage_destination_name ?? t("summary.noStorage"),
                })
              : state === "unprotected" && !canManage
                ? t("state.unprotected.bodyReadOnly")
                : t(`state.${state}.body`)}
          </p>
        </div>

        {/* Column on a phone so long labels cannot overflow (buttons never
            wrap). Read-only roles see the buttons disabled with a reason. */}
        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
          <Button
            variant="outline"
            onClick={onEdit}
            disabled={!canManage}
            disabledReason={canManage ? null : noPermission}
            className="w-full sm:w-auto"
          >
            <Pencil className="size-4" />
            {target ? t("editSettings") : t("setUp")}
          </Button>
          {target ? (
            <Button
              onClick={onBackUpNow}
              disabled={!canManage || running || Boolean(blockedReason)}
              disabledReason={canManage ? blockedReason : noPermission}
              className="w-full sm:w-auto"
            >
              {running ? (
                <Loader2 className="size-4 animate-spin" />
              ) : (
                <PlayCircle className="size-4" />
              )}
              {t("backUpNow")}
            </Button>
          ) : null}
        </div>
      </div>

      {target ? (
        <CardContent className="px-5 py-4">
          <dl className="grid gap-x-6 gap-y-5 sm:grid-cols-3">
            {facts.map((fact) => (
              <div key={fact.label} className="min-w-0">
                <dt className="text-[0.6875rem] font-medium uppercase tracking-wide text-muted-foreground">
                  {fact.label}
                </dt>
                <dd className="mt-0.5 truncate text-sm font-semibold tabular-nums">
                  {fact.value}
                </dd>
              </div>
            ))}
          </dl>

          {/* Exclusions change what a restore returns, so they are shown even
              when there are no patterns to list. */}
          {excludes > 0 || target ? (
            <div className="mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t pt-3">
              <p className="text-xs text-muted-foreground">
                {excludes > 0 ? t("summary.excludes", { count: excludes }) : null}
              </p>
              {/* Quiet and last, so it is not pressed by accident beside
                  "Back up now". */}
              {target ? (
                <Button
                  variant="ghost"
                  size="sm"
                  onClick={onTurnOff}
                  disabled={!canTurnOff || Boolean(turnOffBlockedReason)}
                  disabledReason={canTurnOff ? turnOffBlockedReason : noPermission}
                  className="ml-auto [--destructive-ink:color-mix(in_oklch,var(--destructive),var(--foreground)_22%)] text-(--destructive-ink) hover:bg-destructive/10 hover:text-(--destructive-ink) dark:text-destructive dark:hover:text-destructive"
                >
                  <PowerOff className="size-4" />
                  {t("turnOff.action")}
                </Button>
              ) : null}
            </div>
          ) : null}
        </CardContent>
      ) : null}
    </Card>
  );
}

// The History screen's table without the Site column, so both describe a backup identically.
function RecentBackups({
  backups,
  total = 0,
  applicationId,
  canRestore,
  canManage,
  onRestore,
  onRetry,
  onClear,
  busyId,
  queued = false,
  stalled = false,
  retryBlockedReason = null,
  failed = false,
  forbidden = false,
  restoreInFlight = false,
}) {
  const t = useTranslations("backups.application");
  const router = useRouter();

  // A failed request is not evidence that the site has no history.
  const emptyMessage = forbidden ? t("historyForbidden") : failed ? t("historyFailed") : t("noRuns");

  const listProps = {
    backups,
    canRestore,
    // Same permission as restore: deleting removes the archive from the bucket.
    canDelete: canRestore,
    onDeleted: () => router.refresh(),
    canRun: canManage,
    onRestore,
    onRetry,
    onClear,
    canClear: canManage,
    busyId,
    retryBlockedFor: retryBlockedReason ? () => retryBlockedReason : null,
    restoreInFlight,
    showSite: false,
  };

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex flex-wrap items-center gap-2 border-b px-5 py-3.5">
        {/* The count only shows when the list is capped at the newest five. */}
        <div className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-0.5">
          <h3 className="text-base font-semibold tracking-tight">{t("recentTitle")}</h3>
          {total > backups.length ? (
            <span className="text-xs tabular-nums text-muted-foreground">
              {t("showing", { shown: backups.length, total })}
            </span>
          ) : null}
        </div>
        {/* Sized to match the 36px RefreshButton beside it. */}
        <div className="flex shrink-0 items-center gap-2">
          <RefreshButton />
          {/* Full history needs the permission this list was refused for. */}
          {forbidden ? null : (
            <Button asChild variant="outline">
              <Link href={`/backups/history?application=${applicationId}`}>
                <History className="size-4" />
                {t("viewAll")}
              </Link>
            </Button>
          )}
        </div>
      </div>

      {/* A queued run has no row yet; this shows where it will appear and
          disappears once it does. */}
      {queued ? (
        <div
          role="status"
          className={cn(
            "flex items-start gap-2.5 border-b px-5 py-3 text-sm",
            stalled ? "bg-warning/10 text-foreground" : "bg-muted/40 text-muted-foreground",
          )}
        >
          {stalled ? (
            <CircleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
          ) : (
            <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-primary" />
          )}
          <span>{stalled ? t("queuedStalled") : t("queuedNote")}</span>
        </div>
      ) : null}

      <CardContent className="p-0">
        {/* Cards below xl: with a site's columns the table needs ~930px. */}
        <div className="xl:hidden p-4">
          {backups.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">{emptyMessage}</p>
          ) : (
            <BackupsCards {...listProps} />
          )}
        </div>
        <div className="hidden xl:block">
          <BackupsHistoryTable {...listProps} emptyMessage={emptyMessage} bare />
        </div>
      </CardContent>
    </Card>
  );
}
