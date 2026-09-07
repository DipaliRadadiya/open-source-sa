"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { History, RotateCcw, User, Cog } from "lucide-react";
import { restoreEnvironment } from "@/lib/api/environment";
import { apiMessage } from "@/lib/api/error-message";
import {
  actorOf,
  changedKeys,
  unrestorableReason,
} from "@/lib/applications/environment-history";
import { Badge } from "@/components/ui/badge";
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
 * Key names only — the values are the reason this screen is permission-gated,
 * and a history holding old ones would put every rotated password somewhere it
 * does not belong.
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
}) {
  const t = useTranslations("applications.environment.history");
  const router = useRouter();
  const [pending, setPending] = useState(null);
  const [busy, setBusy] = useState(false);

  async function confirmRestore() {
    setBusy(true);
    try {
      await restoreEnvironment(appId, { backup: pending.backup });
      toast.success(t("restored"));
      setPending(null);
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
          <ul className="divide-y">
            {entries.map((entry) => (
              <HistoryRow
                key={entry.id}
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
        onOpenChange={(next) => (next ? null : setPending(null))}
        icon={RotateCcw}
        title={t("confirmTitle")}
        description={t("confirmBody")}
        cancelLabel={t("cancel")}
        confirmLabel={t("confirmSubmit")}
        pending={busy}
        onConfirm={confirmRestore}
      />
    </Card>
  );
}

function HistoryRow({ entry, canManage, onRestore }) {
  const t = useTranslations("applications.environment.history");
  const actor = actorOf(entry);
  const keys = changedKeys(entry);
  const blocked = unrestorableReason(entry);

  return (
    <li className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0">
      <div className="min-w-0 space-y-1.5">
        <p className="flex items-center gap-1.5 text-sm">
          {actor.kind === "system" ? (
            <Cog className="size-3.5 shrink-0 text-muted-foreground" />
          ) : (
            <User className="size-3.5 shrink-0 text-muted-foreground" />
          )}
          <span className="font-medium">
            {actor.kind === "user"
              ? actor.username
              : actor.kind === "system"
                ? t("bySystem")
                : t("byUnknown")}
          </span>
          <span className="text-muted-foreground">
            {entry.action === "environment_restored"
              ? t("actionRestored")
              : t("actionUpdated")}
          </span>
        </p>

        {keys.length ? (
          <div className="flex flex-wrap gap-1">
            {keys.map((key) => (
              <Badge
                key={key}
                variant="outline"
                className="font-mono text-[11px] font-normal"
              >
                {key}
              </Badge>
            ))}
          </div>
        ) : entry.action === "environment_updated" ? (
          // A save that changed no key at all — comments, spacing, or Save
          // pressed on an untouched file. Worth its own words: a blank space
          // reads as missing information rather than as "nothing changed".
          <p className="text-xs text-muted-foreground">{t("noKeys")}</p>
        ) : null}

        {/* The exact time on hover; the readable one on screen. */}
        <p className="text-xs text-muted-foreground" title={entry.created_at}>
          {entry.created_at_human}
        </p>
      </div>

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
            <RotateCcw className="size-3.5" />
            {t("restore")}
          </Button>
        </ReasonTooltip>
      ) : null}
    </li>
  );
}
