"use client";

import { useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  CircleSlash,
  History,
  Loader2,
  PauseCircle,
  Pencil,
  PlayCircle,
  ShieldCheck,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { BACKUP_IN_FLIGHT } from "@/lib/schemas/backup";
import { retryBackup, runBackupNow } from "@/lib/api/backups";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { AutoRefresh } from "@/components/ui/auto-refresh";
import { BackupsCards } from "@/components/backups/backups-cards";
import { BackupsHistoryTable } from "@/components/backups/backups-history-table";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { ActiveRestore } from "@/components/backups/active-restore";
import { RestoreDialog } from "@/components/backups/restore-dialog";
import { SetupBackupsDialog } from "@/components/backups/setup-backups-dialog";

/**
 * This site's backups: whether it is protected, how, and what has run.
 *
 * A status screen, not a settings screen. The configuration form used to sit
 * expanded on the page, which made a page you visit to *check* something look
 * like a page you came to *change* something. It now lives in the same modal
 * the server-level screen uses — one component, so the two doors cannot drift.
 */
export function BackupsPanel({
  application,
  target,
  destinations,
  backups,
  activeRestore = null,
  canManage,
  canRestore,
}) {
  const t = useTranslations("backups.application");
  const router = useRouter();
  const [running, setRunning] = useState(false);
  const [retryingId, setRetryingId] = useState(null);
  const [restoring, setRestoring] = useState(null);
  const [editing, setEditing] = useState(false);
  // Seeded from the server, then replaced the moment a restore is started here
  // so the progress appears on the click rather than after a round trip.
  const [restore, setRestore] = useState(activeRestore);

  // A run in flight is the one moment this page changes without the user
  // touching it. Polling only then keeps a page nobody is waiting on quiet.
  const busy = backups.some((backup) => BACKUP_IN_FLIGHT.includes(backup.status));

  // Re-runs the failed backup itself. "Back up now" starts a fresh run from
  // the site's current state — a different thing, and not what someone looking
  // at a failed row means.
  async function retry(backup) {
    setRetryingId(backup.id);
    try {
      await retryBackup(backup.id);
      toast.success(t("retryStarted"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("retryFailed")));
    } finally {
      setRetryingId(null);
    }
  }

  async function backUpNow() {
    setRunning(true);
    try {
      await runBackupNow(application.id);
      toast.success(t("started"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("startFailed")));
    } finally {
      setRunning(false);
    }
  }

  return (
    <div className="space-y-6">
      {busy ? <AutoRefresh intervalMs={5000} stopAfterMs={600000} /> : null}

      {/* Above everything: a restore is rewriting this site's files and
          database right now, and it carries the undo once it lands. */}
      {restore ? (
        <ActiveRestore
          key={restore.id}
          restore={restore}
          applicationDomain={application.domain}
        />
      ) : null}

      <ProtectionCard
        target={target}
        canManage={canManage}
        running={running}
        onBackUpNow={backUpNow}
        onEdit={() => setEditing(true)}
      />

      <RecentBackups
        backups={backups}
        applicationId={application.id}
        canRestore={canRestore}
        canManage={canManage}
        onRestore={setRestoring}
        onRetry={retry}
        busyId={retryingId}
      />

      {/* The same modal the Backups screen opens, in edit mode when a target
          already exists. `applicationId` is fixed here, so the site picker
          never renders. */}
      <SetupBackupsDialog
        open={editing}
        onOpenChange={setEditing}
        applicationId={application.id}
        destinations={destinations}
        target={target}
      />

      <RestoreDialog
        key={restoring?.id}
        backup={
          restoring
            ? {
                ...restoring,
                application_domain: application.domain,
                application_name: application.name,
              }
            : null
        }
        open={Boolean(restoring)}
        onOpenChange={(next) => (next ? null : setRestoring(null))}
        onStarted={(started) => {
          setRestoring(null);
          if (started) setRestore(started);
          router.refresh();
        }}
      />
    </div>
  );
}

const STATE = {
  protected: { icon: ShieldCheck, tone: "bg-success/10 text-success", ring: "border-success/30" },
  paused: { icon: PauseCircle, tone: "bg-warning/15 text-warning", ring: "border-warning/30" },
  unprotected: {
    icon: CircleSlash,
    tone: "bg-destructive/10 text-destructive",
    ring: "border-destructive/30",
  },
};

function stateOf(target) {
  if (!target) return "unprotected";
  // A disabled target and a manual one both run on no schedule. That state
  // looks configured on any screen that only asks "is there a target?", and
  // backs up nothing — so it is never reported as protected.
  if (!target.enabled || target.frequency === "manual") return "paused";
  return "protected";
}

/**
 * Everything about this site's protection, in one card: the headline, the six
 * facts, and the two actions. No form — the answer to "am I covered?" should
 * not require reading a set of inputs.
 */
function ProtectionCard({ target, canManage, running, onBackUpNow, onEdit }) {
  const t = useTranslations("backups.application");
  const state = stateOf(target);
  const { icon: Icon, tone, ring } = STATE[state];

  const facts = target
    ? [
        { label: t("summary.what"), value: target.type_title ?? target.type },
        { label: t("summary.howOften"), value: target.frequency_title ?? target.frequency },
        {
          label: t("summary.keeps"),
          value: t("summary.keepsValue", { count: target.retention_count }),
        },
        {
          label: t("summary.where"),
          value: target.storage_destination_name ?? t("summary.noStorage"),
        },
        {
          label: t("summary.lastBackup"),
          value: target.last_run_at_human ?? t("neverRun"),
        },
        {
          label: t("summary.nextBackup"),
          // `is_due` outranks the timestamp: a brand-new target runs its first
          // backup on the next scheduler tick, not at tonight's slot, so
          // showing `next_run_at` alone would name tomorrow at exactly the
          // moment the first backup is about to run.
          value: target.is_due
            ? t("summary.runsShortly")
            : (target.next_run_at_human ?? t("summary.noNextRun")),
        },
      ]
    : [];

  const excludes = (target?.file_excludes?.length ?? 0) + (target?.database_excludes?.length ?? 0);

  return (
    <Card className={cn("gap-0 overflow-hidden py-0 shadow-sm", ring)}>
      <div className="flex flex-col items-start gap-3 border-b px-5 py-4 sm:flex-row sm:items-center">
        <span className={cn("flex size-10 shrink-0 items-center justify-center rounded-lg", tone)}>
          <Icon className="size-5" />
        </span>
        <div className="min-w-0 flex-1">
          <p className="font-semibold tracking-tight">{t(`state.${state}.title`)}</p>
          {/* `state.protected.body` takes {schedule} and {destination}. The
              rewrite called it without them and the page rendered the raw
              placeholders — pass the values, and give the other states their
              own argument-free branch rather than one call for all three. */}
          <p className="text-sm text-muted-foreground">
            {state === "protected"
              ? t("state.protected.body", {
                  schedule: target.frequency_title ?? target.frequency,
                  destination: target.storage_destination_name ?? t("summary.noStorage"),
                })
              : state === "unprotected" && !canManage
                ? t("state.unprotected.bodyReadOnly")
                : t(`state.${state}.body`)}
          </p>
        </div>

        {/* Stacked on a phone, side by side from `sm`. `flex-1` was not
            enough: a flex item will not shrink below its own content, and
            these buttons never wrap their labels — so at a larger text size
            the pair simply ran past the card's edge and clipped. A column
            cannot overflow no matter how wide the labels get. */}
        {canManage ? (
          <div className="flex w-full flex-col gap-2 sm:w-auto sm:flex-row sm:items-center">
            <Button variant="outline" onClick={onEdit} className="w-full sm:w-auto">
              <Pencil className="size-4" />
              {target ? t("editSettings") : t("setUp")}
            </Button>
            {target ? (
              <Button onClick={onBackUpNow} disabled={running} className="w-full sm:w-auto">
                {running ? (
                  <Loader2 className="size-4 animate-spin" />
                ) : (
                  <PlayCircle className="size-4" />
                )}
                {t("backUpNow")}
              </Button>
            ) : null}
          </div>
        ) : null}
      </div>

      {target ? (
        <CardContent className="px-5 py-4">
          {/* Six facts on one grid rather than six rows: this is a readout, and
              a readout should be scannable in a glance, not read line by line. */}
          {/* Label small and quiet, value large and dark. Six labels and six
              values at the same size made the reader work out which was which
              on every cell; the size difference does that for them. */}
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

          {/* Exclusions change what a restore gives you back, so their
              existence belongs here even when the patterns do not. */}
          {excludes > 0 ? (
            <p className="mt-4 border-t pt-3 text-xs text-muted-foreground">
              {t("summary.excludes", { count: excludes })}
            </p>
          ) : null}
        </CardContent>
      ) : null}
    </Card>
  );
}

/**
 * This site's recent runs — the same table the History screen uses, minus the
 * Site column.
 *
 * It was a hand-built list of rows before, and that was the problem: no column
 * headers, so "4 hours ago" and "Not yet" sat under nothing and the reader had
 * to work out what each value was. Row heights varied too, because a note
 * under the row made some rows two lines and others one. Sharing the table
 * gives it headers, one row rhythm, and a guarantee the two screens describe a
 * backup identically.
 */
function RecentBackups({
  backups,
  applicationId,
  canRestore,
  canManage,
  onRestore,
  onRetry,
  busyId,
}) {
  const t = useTranslations("backups.application");

  const listProps = {
    backups,
    canRestore,
    canRun: canManage,
    onRestore,
    onRetry,
    busyId,
    showSite: false,
  };

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      <div className="flex flex-wrap items-center gap-2 border-b px-5 py-3.5">
        <h3 className="min-w-0 flex-1 text-base font-semibold tracking-tight">
          {t("recentTitle")}
        </h3>
        <div className="flex shrink-0 items-center gap-1">
          <RefreshButton />
          <Button asChild variant="ghost" size="sm">
            <Link href={`/backups/history?application=${applicationId}`}>
              <History className="size-4" />
              {t("viewAll")}
            </Link>
          </Button>
        </div>
      </div>

      <CardContent className="p-0">
        <div className="lg:hidden p-4">
          {backups.length === 0 ? (
            <p className="py-6 text-center text-sm text-muted-foreground">{t("noRuns")}</p>
          ) : (
            <BackupsCards {...listProps} />
          )}
        </div>
        <div className="hidden lg:block">
          <BackupsHistoryTable {...listProps} emptyMessage={t("noRuns")} bare />
        </div>
      </CardContent>
    </Card>
  );
}
