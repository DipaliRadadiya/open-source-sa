import { useTranslations } from "next-intl";
import { TriangleAlert, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { IconTooltip } from "@/components/ui/icon-tooltip";
import { failureReason } from "@/lib/files/bulk-result";

/**
 * What a bulk operation actually did, when it did not do all of it.
 *
 * Deliberately not a toast. People read a notification's COLOUR, not its text —
 * a green toast saying "12 of 15 moved" is read as "moved", and a red one as
 * "nothing moved". Partial success needs its own state and its own tone, and it
 * needs to sit still: this is a list of paths to read, not a status to glance
 * at, and it must not evaporate on a three-second timer.
 *
 * Stays until dismissed or until the next action replaces it.
 */
export function BulkResultPanel({ result, onDismiss }) {
  const t = useTranslations("applications.files");
  if (!result?.failed?.length) return null;

  const { failed, succeeded, total, allFailed } = result;

  return (
    <div
      role="status"
      className="space-y-2 rounded-xl border border-warning/40 bg-warning/5 p-4"
    >
      <div className="flex items-start justify-between gap-3">
        <p className="flex items-start gap-2 text-sm font-medium text-warning">
          <TriangleAlert className="mt-0.5 size-4 shrink-0" />
          {allFailed
            ? t("bulk.noneDone", { total })
            : t("bulk.partlyDone", { done: succeeded.length, total })}
        </p>
        <IconTooltip label={t("bulk.dismiss")}>
          <Button
            type="button"
            variant="ghost"
            size="icon"
            className="size-6 shrink-0"
            aria-label={t("bulk.dismiss")}
            onClick={onDismiss}
          >
            <X className="size-3.5" />
          </Button>
        </IconTooltip>
      </div>

      {/* Bounded: a failed batch of 250 must not push the file list off the
          page. Each row names the path AND why, because "3 failed" without
          which three is not something anyone can act on. */}
      <ul className="max-h-48 space-y-1 overflow-y-auto">
        {failed.map((entry) => (
          <li
            key={entry.path}
            className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5 text-xs"
          >
            <span className="font-mono break-all text-foreground">{entry.path}</span>
            <span className="text-muted-foreground">
              {failureReason(entry.reason, t)}
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
