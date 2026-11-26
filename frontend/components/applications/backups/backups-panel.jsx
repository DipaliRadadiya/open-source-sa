"use client";

import { HeadIcon } from "@/components/ui/head-icon";
import { useEffect, useState } from "react";
import Link from "@/components/ui/app-link";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { CircleAlert, CircleSlash, Database, History, Loader2, PauseCircle, Pencil, PlayCircle, PowerOff, ShieldAlert, ShieldCheck, Archive } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { EmptyArt } from "@/components/data-table/empty-art";
import { cn } from "@/lib/utils";
import { formatBytes } from "@/lib/format/bytes";
import { BACKUP_IN_FLIGHT, RESTORE_IN_FLIGHT } from "@/lib/schemas/backup";
import { nothingKept } from "@/lib/backups/reason";
import { isBackupQueued, newestBackupId } from "@/lib/backups/queued";
import { scheduleWhen } from "@/lib/backups/schedule-time";
import { frequencyLabel } from "@/lib/backups/frequency";
import { clearStuckBackup, retryBackup, runBackupNow } from "@/lib/api/backups";
import { isRetryable } from "@/lib/backups/retry-eligibility";
import { runDatabaseBackupNow } from "@/lib/api/database-backups";
import { useSubjectTranslations } from "@/hooks/use-subject-translations";
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
import { FACT_CELL, FACT_FRAME, FACT_GRID, FACT_LABEL } from "@/lib/theme/facts";
import { DENIED } from "@/components/ui/reason-tooltip";

const QUEUE_STALLED_MS = 3 * 60 * 1000;

export function BackupsPanel({
  application = null,
  subject = "application",
  database = null,
  target,
  destinations,
  backups,
  archiveDestinations = [],
  keptCount = null,
  total = 0,
  activeRestore = null,
  canManage,
  canRestore,
  canTurnOff = false,
  databaseCounts = null,
  databasesKnown = false,
  siteDatabases = [],
  siteDatabasesKnown = true,
  unattachedDatabases = [],
  engines = [],
  needsDatabase = false,
  siteTypes = null,
  canManageDatabases = false,
  backupsFailed = false,
  backupsForbidden = false,
  backupOptions = null,
  hasVolumes = null,
  canHandleArchives = false,
  archived = [],
}) {
  const t = useSubjectTranslations("backups.application", "backups.database", subject);
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const isDatabase = subject === "database";
  const owner = isDatabase ? database : application;
  const confirmValue = isDatabase ? database.name : application.domain;
  const [running, setRunning] = useState(false);
  const [retryingId, setRetryingId] = useState(null);
  const [clearing, setClearing] = useState(null);
  const [restoring, setRestoring] = useState(null);
  const [editing, setEditing] = useState(false);
  const [turningOff, setTurningOff] = useState(false);
  const [restore, setRestore] = useState(activeRestore);
  const [restoreStartedHere, setRestoreStartedHere] = useState(false);
  const [restoreStatus, setRestoreStatus] = useState(null);
  const restoreRunning = RESTORE_IN_FLIGHT.includes(restoreStatus ?? restore?.status);
  const [queuedAfter, setQueuedAfter] = useState(null);
  const [stalled, setStalled] = useState(false);

  const busy = backups.some((backup) => BACKUP_IN_FLIGHT.includes(backup.status));

  const newestId = newestBackupId(backups);
  const queued = isBackupQueued(backups, queuedAfter);

  if (queuedAfter !== null && !queued) setQueuedAfter(null);

  useEffect(() => {
    if (!queued || stalled) return undefined;
    const id = setTimeout(() => setStalled(true), QUEUE_STALLED_MS);
    return () => clearTimeout(id);
  }, [queued, stalled]);

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
      await (isDatabase ? runDatabaseBackupNow(database.id) : runBackupNow(application.id));
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
      {busy || queued ? (
        <AutoRefresh intervalMs={5000} stopAfterMs={600000} />
      ) : (
        <AutoRefresh intervalMs={60000} stopAfterMs={30 * 60 * 1000} quiet />
      )}

      {restore ? (
        <ActiveRestore
          key={restore.id}
          restore={restore}
          onStatusChange={setRestoreStatus}
          applicationDomain={confirmValue}
          subject={subject}
          database={database}
          restoredSafetyCopy={Boolean(restore.restored_safety_copy)}
          scrollIntoView={restoreStartedHere}
        />
      ) : null}

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
        lastBackups={backups.slice(0, 1)}
      />

      <ProtectionCard
        subject={subject}
        target={target}
        options={backupOptions}
        noneKept={nothingKept(target)}
        canManage={canManage}
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
        historyHref={isDatabase ? `/backups/history?database=${database.id}` : `/backups/history?application=${application.id}`}
        archiveAccess={isDatabase ? canHandleArchives : null}
        subject={subject}
        canRestore={canRestore}
        canManage={canManage}
        onRestore={setRestoring}
        onRetry={retry}
        onClear={setClearing}
        busyId={retryingId}
        queued={queued}
        stalled={stalled}
        retryBlockedReason={
          restoreRunning ? t("restoreRunning") : queued || busy ? t("alreadyRunning") : null
        }
        restoreInFlight={restoreRunning}
        hasTarget={Boolean(target)}
      />

      {isDatabase && archived.length ? (
        <ArchivedBackups
          backups={archived}
          database={database}
          blockedReason={
            !canRestore
              ? null
              : !target
                ? t("archived.needsSchedule")
                : restoreRunning
                  ? t("restoreRunning")
                  : null
          }
          canRestore={canRestore}
          onRestore={setRestoring}
        />
      ) : null}

      <SetupBackupsDialog
        open={editing}
        onOpenChange={setEditing}
        applicationId={owner.id}
        database={database}
        destinations={destinations}
        applicationName={owner.name}
        target={target}
        keptCount={keptCount}
        databaseCounts={databaseCounts}
        databasesKnown={databasesKnown}
        siteTypes={siteTypes}
        siteType={application?.site_type}
        servingProfile={application?.serving_profile}
        hasVolumes={hasVolumes}
        options={backupOptions}
        onStarted={() => {
          setStalled(false);
          setQueuedAfter(newestId);
        }}
      />

      {target || turningOff ? (
        <TurnOffBackupsDialog
          open={turningOff}
          onOpenChange={setTurningOff}
          application={owner}
          subject={subject}
          target={target}
          count={backupsFailed ? null : total}
          destinationNames={[
            ...new Set(
              [
                target?.storage_destination_name,
                ...(backups ?? []).map((backup) => backup.storage_destination_name),
                ...archiveDestinations,
              ].filter(Boolean),
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
                application_domain: confirmValue,
                application_name: owner.name,
                files_only:
                  !isDatabase &&
                  Boolean(siteTypes?.length) &&
                  !needsDatabase &&
                  hasNoDatabase(databaseCounts, databasesKnown, application?.id) === true,
              }
            : null
        }
        subject={subject}
        database={database}
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
  protected: { icon: ShieldCheck, tone: "bg-success/10 text-success" },
  empty: { icon: ShieldAlert, tone: "bg-warning/15 text-warning" },
  paused: { icon: PauseCircle, tone: "bg-warning/15 text-warning" },
  unprotected: {
    icon: CircleSlash,
    tone: "bg-destructive/10 text-destructive",
  },
};

function stateOf(target, noneKept) {
  if (!target) return "unprotected";
  if (!target.enabled || target.frequency === "manual") return "paused";
  if (noneKept) return "empty";
  return "protected";
}

function ProtectionCard({ subject = "application", target, options = null, noneKept = false, canManage, running, blockedReason, onBackUpNow, onEdit, canTurnOff = false, turnOffBlockedReason = null, onTurnOff }) {
  const t = useSubjectTranslations("backups.application", "backups.database", subject);
  const state = stateOf(target, noneKept);
  const { icon: Icon, tone } = STATE[state];
  const format = useFormatter();

  const when = scheduleWhen(target, options, format);
  const tb = useTranslations("backups");
  const frequencyTitle = frequencyLabel(target, (frequency) => tb("pausedFrequency", { frequency }));
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
        subject === "database" ? null : { label: t("summary.what"), value: target.type_title ?? target.type },
        {
          label: t("summary.howOften"),
          // The schedule is in the app's timezone; converting would show an hour it never runs at.
          value: schedule,
        },
        {
          label: t("summary.keeps"),
          // 0 means keep every backup.
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
          value: target.last_backup_at_human ?? (target.last_run_at ? t("noneKept") : t("neverRun")),
        },
        {
          label: t("summary.nextBackup"),
          value: target.is_due
            ? t("summary.runsShortly")
            : (target.next_run_at_human ?? t("summary.noNextRun")),
        },
      ].filter(Boolean)
    : [];

  const excludes = (target?.file_excludes?.length ?? 0) + (target?.database_excludes?.length ?? 0);
  const noPermission = DENIED;

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className={cn("flex flex-col items-start gap-3 px-5 py-4 sm:flex-row sm:items-center", target && "border-b")}>
        <span className={cn("flex size-10 shrink-0 items-center justify-center rounded-lg", tone)}>
          <Icon className="size-5" />
        </span>
        <div className="min-w-0 flex-1">
          <h2 className="text-[15px] font-semibold tracking-tight">{t(`state.${state}.title`)}</h2>
          {state === "protected" ? null : (
            <p className="text-sm text-muted-foreground">
              {state === "unprotected" && !canManage
                ? t("state.unprotected.bodyReadOnly")
                : t(`state.${state}.body`)}
            </p>
          )}
        </div>

        <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
          <Button
            variant={target ? "outline" : "default"}
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
          <div className={FACT_FRAME}>
          <dl className={cn(FACT_GRID, "sm:grid-cols-3")}>
            {facts.map((fact) => (
              <div key={fact.label} className={FACT_CELL}>
                <dt className={FACT_LABEL}>
                  {fact.label}
                </dt>
                <dd className="mt-0.5 truncate text-sm font-medium tabular-nums">
                  {fact.value}
                </dd>
              </div>
            ))}
          </dl>
          </div>

          {excludes > 0 || target ? (
            <div className="mt-4 flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t pt-3">
              <p className="text-xs text-muted-foreground">
                {excludes > 0 ? t("summary.excludes", { count: excludes }) : null}
              </p>
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

function RecentBackups({
  backups,
  total = 0,
  historyHref,
  archiveAccess = null,
  subject = "application",
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
  hasTarget = false,
}) {
  const t = useSubjectTranslations("backups.application", "backups.database", subject);
  const router = useRouter();
  const firstRun = backups.length === 0 && !failed && !forbidden && !queued;

  const emptyMessage = forbidden ? t("historyForbidden") : failed ? t("historyFailed") : t("noRuns");

  const listProps = {
    backups,
    canRestore,
    canDelete: archiveAccess ?? canRestore,
    canDownload: archiveAccess ?? canRestore,
    onDeleted: () => router.refresh(),
    canRun: canManage,
    onRestore,
    onRetry,
    onClear,
    canClear: canManage,
    busyId,
    retryBlockedFor: retryBlockedReason ? () => retryBlockedReason : null,
    retryable: (backup) => isRetryable(backup, backups),
    restoreInFlight,
    showSite: false,
    subject,
  };

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="flex flex-wrap items-center gap-3 border-b px-5 py-3.5">
        <HeadIcon icon={History} />
        <div className="flex min-w-0 flex-1 flex-wrap items-baseline gap-x-2 gap-y-0.5">
          <h3 className="text-[15px] font-semibold tracking-tight">{t("recentTitle")}</h3>
          {total > backups.length ? (
            <span className="text-xs tabular-nums text-muted-foreground">
              {t("showing", { shown: backups.length, total })}
            </span>
          ) : null}
        </div>
        <div className="flex shrink-0 items-center gap-2">
          <RefreshButton />
          {forbidden ? null : (
            <Button asChild variant="outline">
              <Link href={historyHref}>
                <History className="size-4" />
                {t("viewAll")}
              </Link>
            </Button>
          )}
        </div>
      </div>

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

      {firstRun ? (
        <FirstBackupEmpty hasTarget={hasTarget} subject={subject} />
      ) : (
      <CardContent className="p-0">
        <div className="xl:hidden p-4">
          {backups.length === 0 ? (
            <EmptyState compact icon={Archive} title={emptyMessage} />
          ) : (
            <BackupsCards {...listProps} />
          )}
        </div>
        <div className="hidden @container/history xl:block">
          <BackupsHistoryTable {...listProps} emptyMessage={emptyMessage} emptyIcon={Archive} bare />
        </div>
      </CardContent>
      )}
    </Card>
  );
}

function FirstBackupEmpty({ hasTarget, subject = "application" }) {
  const t = useSubjectTranslations("backups.application", "backups.database", subject);
  return (
    <div className="flex flex-col items-center gap-4 px-6 py-10 text-center">
      <EmptyArt icon={Archive} />
      <div className="max-w-md space-y-1.5">
        <p className="font-semibold">{t("noRuns")}</p>
        {hasTarget ? <p className="text-sm text-muted-foreground">{t("emptyHint.scheduled")}</p> : null}
      </div>
    </div>
  );
}

function ArchivedBackups({ backups, database, canRestore, blockedReason, onRestore }) {
  const t = useTranslations("backups.database.archived");
  const tHistory = useTranslations("backups.history");
  const format = useFormatter();
  const reason = canRestore ? blockedReason : DENIED;

  return (
    <Card className="gap-0 overflow-hidden py-0">
      <div className="space-y-0.5 border-b px-5 py-3.5">
        <h3 className="text-[15px] font-semibold tracking-tight">{t("title")}</h3>
        <p className="text-sm text-muted-foreground">{t("body", { name: database.name })}</p>
      </div>
      <ul className="divide-y">
        {backups.map((backup) => (
          <li key={backup.id} className="flex flex-wrap items-center gap-3 px-5 py-3">
            <div className="min-w-48 flex-1">
              <p className="truncate font-medium">{backup.database_name ?? tHistory("unknownApplication")}</p>
              <p className="text-xs tabular-nums text-muted-foreground">
                {[backup.created_at_human, backup.size_bytes ? formatBytes(backup.size_bytes, format) : null]
                  .filter(Boolean)
                  .join(" · ")}
              </p>
            </div>
            <Button
              variant="outline"
              size="sm"
              onClick={() => onRestore(backup)}
              disabled={!canRestore || Boolean(reason) || backup.status !== "verified"}
              disabledReason={reason ?? (backup.status !== "verified" ? (backup.status_title ?? backup.status) : null)}
            >
              <History className="size-4" />
              {t("restore")}
            </Button>
          </li>
        ))}
      </ul>
    </Card>
  );
}
