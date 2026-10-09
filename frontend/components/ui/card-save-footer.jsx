import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

// `submit` makes Save a submit button for react-hook-form cards; otherwise it calls `onSave`.
export function CardSaveFooter({
  saving,
  dirty,
  saveReason,
  onSave,
  onDiscard,
  submit = false,
  savingNote,
  // Shown while unsaved, for a save with a non-obvious cost (e.g. an FPM reload).
  note,
  // Prints `saveReason` beside the disabled button, not only in the tooltip.
  showReason = false,
  saveLabel,
  // Off when the screen already marks unsaved changes elsewhere.
  showUnsaved = true,
}) {
  const t = useTranslations("common.saveFooter");

  return (
    <div className="flex flex-wrap items-center justify-end gap-2 border-t bg-muted/30 px-5 py-3">
      {/* These saves reload the web server, so the wait is explained. */}
      {saving && savingNote ? (
        <p className="mr-auto text-xs text-muted-foreground">{savingNote}</p>
      ) : null}
      {/* Badge and note travel together so a long note cannot push the buttons
          onto a line of their own. */}
      {!saving && dirty && (showUnsaved || note) ? (
        <div className="mr-auto flex min-w-0 flex-wrap items-center gap-2">
          {showUnsaved ? <Badge variant="warning">{t("unsaved")}</Badge> : null}
          {note ? <p className="text-xs text-muted-foreground">{note}</p> : null}
        </div>
      ) : null}
      {!saving && !dirty && showReason && saveReason ? (
        <p className="mr-auto text-xs text-muted-foreground">{saveReason}</p>
      ) : null}
      {/* Wraps rather than shrink-0: under justify-end it would overflow to the left. */}
      <div className="flex min-w-0 flex-wrap items-center justify-end gap-2">
        {dirty && onDiscard ? (
          <Button type="button" variant="ghost" onClick={onDiscard} disabled={saving}>
            {t("discard")}
          </Button>
        ) : null}
        <ReasonTooltip reason={saveReason}>
          <Button
            type={submit ? "submit" : "button"}
            onClick={submit ? undefined : onSave}
            disabled={Boolean(saveReason) || saving}
            // Long, localised labels wrap instead of widening the card.
            className="h-auto max-w-full py-2 text-center whitespace-normal"
          >
            {saving ? <Loader2 className="size-4 animate-spin" /> : null}
            {saving ? t("saving") : (saveLabel ?? t("save"))}
          </Button>
        </ReasonTooltip>
      </div>
    </div>
  );
}
