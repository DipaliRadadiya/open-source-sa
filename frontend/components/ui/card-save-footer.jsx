import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { DENIED, ReasonTooltip } from "@/components/ui/reason-tooltip";

export function CardSaveFooter({
  saving,
  dirty,
  saveReason,
  onSave,
  onDiscard,
  submit = false,
  savingNote,
  note,
  showReason = false,
  saveLabel,
  showUnsaved = true,
}) {
  const t = useTranslations("common.saveFooter");

  if (saveReason === DENIED) return null;

  return (
    <div className="flex flex-wrap items-center justify-end gap-2 border-t bg-muted/30 px-5 py-3">
      {saving && savingNote ? (
        <p className="mr-auto text-xs text-muted-foreground">{savingNote}</p>
      ) : null}
      {!saving && dirty && (showUnsaved || note) ? (
        <div className="mr-auto flex min-w-0 flex-wrap items-center gap-2">
          {showUnsaved ? <Badge variant="warning">{t("unsaved")}</Badge> : null}
          {note ? <p className="text-xs text-muted-foreground">{note}</p> : null}
        </div>
      ) : null}
      {!saving && !dirty && showReason && saveReason ? (
        <p className="mr-auto text-xs text-muted-foreground">{saveReason}</p>
      ) : null}
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
            className="h-auto min-h-9 max-w-full py-1.5 text-center whitespace-normal"
          >
            {saving ? <Loader2 className="size-4 animate-spin" /> : null}
            {saving ? t("saving") : (saveLabel ?? t("save"))}
          </Button>
        </ReasonTooltip>
      </div>
    </div>
  );
}
