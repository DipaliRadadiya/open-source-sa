"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ChevronDown, Cog, History, Pencil, RotateCcw } from "lucide-react";
import { cn } from "@/lib/utils";
import { restoreEnvironment } from "@/lib/api/environment";
import { apiMessage } from "@/lib/api/error-message";
import {
  actorOf,
  changedKeys,
  unrestorableReason,
} from "@/lib/applications/environment-history";
import {
  EnvironmentDiff,
  useEnvironmentDiff,
} from "@/components/applications/environment/environment-diff";
import { Badge } from "@/components/ui/badge";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Button } from "@/components/ui/button";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";

/**
 * Who changed this application's `.env`, when, and what they touched.
 *
 * The list itself carries key names only. Values are one click further in, and
 * only for a `manage` user — they come off the backup files on demand, never
 * out of the activity log, which is append-only, unpruned, and rendered by an
 * admin-wide screen with different permissions than this one.
 *
 * Restoring from a row puts the file back to what it was *before* that change,
 * which is why each row carries its own backup name. The alternative, and what
 * this replaces, was a list of filenames to match against a log by timestamp.
 */
export function EnvironmentHistoryCard({
  appId,
  entries,
  failed = false,
  canManage = false,
  /*
   * Whether this site runs a process that holds its environment in memory.
   *
   * The editor's own restore dialog has always offered a restart checkbox for
   * these sites; this card — the same endpoint, the same action — sent no flag
   * and told the reader "The application keeps running with the restored
   * values." On a Node site that was simply untrue: the process kept running
   * with the OLD ones. Two doors to one action cannot tell different stories.
   */
  requiresRestart = false,
}) {
  const t = useTranslations("applications.environment.history");
  // The restart control reuses the editor dialog's strings, which live one
  // level up — so there is one sentence describing what restarting does.
  const tEnv = useTranslations("applications.environment");
  const router = useRouter();
  const [pending, setPending] = useState(null);
  const [busy, setBusy] = useState(false);
  // Off by default, matching the editor's dialog: restarting is a visible
  // interruption and should be asked for, not assumed.
  const [restart, setRestart] = useState(false);

  async function confirmRestore() {
    setBusy(true);
    try {
      await restoreEnvironment(appId, { backup: pending.backup, restart });
      toast.success(t("restored"));
      setPending(null);
      setRestart(false);
      // A refresh, not local state: the restore changed the file the editor
      // above is showing, and leaving that stale would put the old text on
      // screen over the new file on disk.
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("restoreFailed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Card>
      <CardHeader>
        <CardTitle className="flex items-center gap-2">
          <History className="size-4" />
          {t("title")}
        </CardTitle>
        <CardDescription>{t("subtitle")}</CardDescription>
      </CardHeader>
      <CardContent>
        {/* A history that could not be read is not an empty history. Saying
            "no changes yet" here would be a confident lie about an audit
            trail, which is worse than admitting the read failed. */}
        {failed ? (
          <p className="text-sm text-muted-foreground">{t("loadFailed")}</p>
        ) : !entries?.length ? (
          <p className="text-sm text-muted-foreground">{t("empty")}</p>
        ) : (
          <ul>
            {entries.map((entry) => (
              <HistoryRow
                key={entry.id}
                appId={appId}
                entry={entry}
                canManage={canManage}
                onRestore={() => setPending(entry)}
              />
            ))}
          </ul>
        )}
      </CardContent>

      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(next) => {
          if (next) return;
          setPending(null);
          // Cleared on close so a checkbox ticked and then abandoned does not
          // silently apply to the next restore.
          setRestart(false);
        }}
        icon={RotateCcw}
        title={t("confirmTitle")}
        description={t("confirmBody")}
        cancelLabel={t("cancel")}
        confirmLabel={t("confirmSubmit")}
        pending={busy}
        onConfirm={confirmRestore}
      >
        {/* Same control, same strings as the editor's restore dialog — one
            wording for one decision. Only shown when the site actually has a
            process to restart. */}
        {requiresRestart ? (
          <div className="flex items-start gap-2.5 rounded-lg border p-3">
            <Checkbox
              id="history-restore-restart"
              checked={restart}
              onCheckedChange={(v) => setRestart(v === true)}
              className="mt-0.5"
            />
            <Label
              htmlFor="history-restore-restart"
              className="text-sm font-normal leading-relaxed"
              hint={tEnv("restore.restartHint")}
            >
              {tEnv("restore.restart")}
            </Label>
          </div>
        ) : null}
      </ConfirmDialog>
    </Card>
  );
}

/*
 * One change, as a line on a timeline: who, what in one sentence, when, and
 * the two things you can do about it. It used to be a stack of five loose
 * lines — actor, a sentence, a timestamp, a ghost "Show values", and a box
 * repeating the sentence — with the Restore button floating on its own.
 */
function HistoryRow({ appId, entry, canManage, onRestore }) {
  const t = useTranslations("applications.environment.history");
  const actor = actorOf(entry);
  const keys = changedKeys(entry);
  const blocked = unrestorableReason(entry);
  const restored = entry.action === "environment_restored";
  // A save that touched no variable has no values to show: the diff would
  // only repeat the sentence already on the row.
  const noKeys = !restored && keys.length === 0;
  const diff = useEnvironmentDiff(appId, entry);
  const canShowValues = canManage && blocked !== "pruned" && !noKeys;
  const Icon = actor.kind === "system" ? Cog : restored ? RotateCcw : Pencil;

  return (
    <li className="group/row relative flex gap-3 pb-5 last:pb-0">
      {/* The rail joining one change to the next. */}
      <span aria-hidden className="absolute top-9 bottom-1 left-4 w-px bg-border group-last/row:hidden" />
      <span
        className={cn(
          "relative flex size-8 shrink-0 items-center justify-center rounded-full",
          actor.kind === "system"
            ? "bg-muted text-muted-foreground"
            : restored
              ? "bg-warning/15 text-warning"
              : "bg-primary/10 text-primary",
        )}
      >
        <Icon className="size-4" />
      </span>

      <div className="min-w-0 flex-1 space-y-2">
        <div className="flex flex-wrap items-start justify-between gap-x-4 gap-y-2">
          <div className="min-w-48 flex-1 space-y-0.5">
            <p className="text-sm">
              <span className="font-medium">
                {actor.kind === "user"
                  ? actor.username
                  : actor.kind === "system"
                    ? t("bySystem")
                    : t("byUnknown")}
              </span>{" "}
              <span className="text-muted-foreground">
                {restored
                  ? t("actionRestored")
                  : noKeys
                    ? t("actionNoKeys")
                    : t("actionChanged", { count: keys.length })}
              </span>
            </p>
            {/* The exact time on hover; the readable one on screen. */}
            <p className="text-xs text-muted-foreground" title={entry.created_at ?? undefined}>
              {entry.created_at_human}
            </p>
            {/* With the sentence, not under the buttons: on a phone the
                buttons wrap below this column, and the keys belong to the
                sentence that counts them. */}
            {keys.length ? (
              <div className="flex flex-wrap gap-1.5 pt-1.5">
                {keys.map((key) => (
                  <Badge key={key} variant="secondary" className="font-mono text-xs font-normal">
                    {key}
                  </Badge>
                ))}
              </div>
            ) : noKeys ? (
              <p className="pt-1 text-xs text-muted-foreground">{t("noKeysNote")}</p>
            ) : null}
          </div>

          <div className="flex shrink-0 flex-wrap gap-2">
            {canShowValues ? (
              <Button
                type="button"
                variant="outline"
                size="sm"
                onClick={diff.toggle}
                aria-expanded={diff.open}
              >
                <ChevronDown className={cn("size-4 transition-transform", diff.open && "rotate-180")} />
                {diff.open ? t("hideChanges") : t("showChanges")}
              </Button>
            ) : null}
            {canManage ? (
              <ReasonTooltip
                reason={
                  blocked === "pruned"
                    ? t("prunedReason")
                    : blocked === "first"
                      ? t("firstSaveReason")
                      : null
                }
              >
                <Button
                  variant="outline"
                  size="sm"
                  disabled={blocked !== null}
                  onClick={onRestore}
                >
                  <RotateCcw className="size-4" />
                  {t("restore")}
                </Button>
              </ReasonTooltip>
            ) : null}
          </div>
        </div>

        {/* Values live behind a click, and only for people who could already
            read them by restoring a backup — it reads the same file. Offered
            for a first save too, where the diff is "everything was added";
            hidden when the backup is pruned and there is nothing to compare. */}
        {canShowValues && diff.open ? <EnvironmentDiff state={diff} /> : null}
      </div>
    </li>
  );
}
