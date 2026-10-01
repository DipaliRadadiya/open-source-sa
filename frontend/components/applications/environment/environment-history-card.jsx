"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { ChevronDown, Cog, History, Loader2, Pencil, RotateCcw } from "lucide-react";
import { cn } from "@/lib/utils";
import { getEnvironmentHistoryPage, restoreEnvironment } from "@/lib/api/environment";
import { useRefresh } from "@/hooks/use-refresh";
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
 * Who changed this application's `.env`, when, and which keys.
 *
 * The list carries key names only. Values are loaded on demand from the backup
 * files, for `manage` users only, never from the activity log (which is
 * unpruned and visible under different permissions).
 *
 * Restoring a row puts the file back to its state *before* that change.
 */
export function EnvironmentHistoryCard({
  appId,
  entries,
  meta = null,
  failed = false,
  canManage = false,
  // The process holds its environment in memory, so a restore needs a
  // restart to take effect; offers the same checkbox as the editor's dialog.
  requiresRestart = false,
}) {
  const t = useTranslations("applications.environment.history");
  // Restart strings are shared with the editor's restore dialog.
  const tEnv = useTranslations("applications.environment");
  const { pending: refreshing, refreshThen } = useRefresh();
  const [pending, setPending] = useState(null);
  const [busy, setBusy] = useState(false);
  // Off by default, matching the editor's dialog.
  const [restart, setRestart] = useState(false);

  // Pages after the first, fetched on request. Dropped when the first page
  // changes, since every row shifts and one would otherwise be hidden.
  const [older, setOlder] = useState({ from: entries, rows: [], page: 1, last: meta?.last_page ?? 1 });
  const [loadingOlder, setLoadingOlder] = useState(false);
  if (older.from !== entries) {
    setOlder({ from: entries, rows: [], page: 1, last: meta?.last_page ?? 1 });
  }
  const shown = [...(entries ?? []), ...older.rows.filter((row) => !entries?.some((entry) => entry.id === row.id))];
  const remaining = Math.max((meta?.total ?? 0) - shown.length, 0);

  async function loadOlder() {
    setLoadingOlder(true);
    try {
      const data = await getEnvironmentHistoryPage(appId, older.page + 1);
      setOlder((current) =>
        current.from === entries
          ? { ...current, rows: [...current.rows, ...data.history], page: current.page + 1, last: data.meta?.last_page ?? current.last }
          : current,
      );
    } catch (error) {
      toast.error(apiMessage(error, t("olderFailed")));
    } finally {
      setLoadingOlder(false);
    }
  }

  async function confirmRestore() {
    setBusy(true);
    try {
      await restoreEnvironment(appId, { backup: pending.backup, restart });
      // Refresh so the editor shows the restored file; close only once it lands.
      refreshThen(() => {
        toast.success(t("restored"));
        setPending(null);
        setRestart(false);
      });
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
        {/* A failed read must not render as "no changes yet". */}
        {failed ? (
          <p className="text-sm text-muted-foreground">{t("loadFailed")}</p>
        ) : !entries?.length ? (
          <p className="text-sm text-muted-foreground">{t("empty")}</p>
        ) : (
          <ul>
            {shown.map((entry) => (
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
        {!failed && older.page < older.last && remaining > 0 ? (
          <Button variant="outline" size="sm" className="mt-5" onClick={loadOlder} disabled={loadingOlder}>
            {loadingOlder ? <Loader2 className="size-4 animate-spin" /> : null}
            {t("showOlder", { count: remaining })}
          </Button>
        ) : null}
      </CardContent>

      <ConfirmDialog
        open={pending !== null}
        onOpenChange={(next) => {
          if (next) return;
          setPending(null);
          // Cleared so an abandoned tick does not apply to the next restore.
          setRestart(false);
        }}
        icon={RotateCcw}
        title={t("confirmTitle")}
        description={t("confirmBody")}
        cancelLabel={t("cancel")}
        confirmLabel={t("confirmSubmit")}
        pending={busy || refreshing}
        onConfirm={confirmRestore}
      >
        {/* Same control and strings as the editor's restore dialog. */}
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

/* One change as a timeline entry: who, what, when, and its actions. */
function HistoryRow({ appId, entry, canManage, onRestore }) {
  const t = useTranslations("applications.environment.history");
  const actor = actorOf(entry);
  const keys = changedKeys(entry);
  const blocked = unrestorableReason(entry);
  const restored = entry.action === "environment_restored";
  // A save that touched no variable has no values to show.
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
            {/* Inside this column so on a phone the keys stay with the sentence, above the wrapped buttons. */}
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

        {/* Values only for `manage` users (who could read them by restoring
            anyway); unavailable once the backup is pruned. */}
        {canShowValues && diff.open ? <EnvironmentDiff state={diff} /> : null}
      </div>
    </li>
  );
}
