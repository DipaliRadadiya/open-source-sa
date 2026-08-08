"use client";

import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

/**
 * The footer strip that saves a settings card.
 *
 * Three screens had grown their own copy — Password Protection, the AI Bot
 * Blocker and the Firewall — and they had already started to disagree: the
 * "this takes a few seconds" note had to be added twice, and only one of them
 * carried an unsaved marker.
 *
 * `submit` picks the button type: the Password Protection card is a real form
 * driven by react-hook-form, the other two save from local state on click.
 * That is the only difference between them, so it is the only knob here.
 */
export function CardSaveFooter({
  saving,
  dirty,
  saveReason,
  onSave,
  onDiscard,
  submit = false,
  savingNote,
  // A card that saves one named thing can say so — "Save PHP settings" rather
  // than a bare "Save" that could belong to anything on the page.
  saveLabel,
  // Off for a screen that already marks the unsaved thing where it lives — the
  // AI Bot Blocker badges the chosen card, and a second marker down here would
  // be the same fact twice.
  showUnsaved = true,
}) {
  const t = useTranslations("common.saveFooter");

  return (
    <div className="flex flex-wrap items-center justify-end gap-2 border-t bg-muted/20 px-5 py-3">
      {/* These saves re-render a vhost, config-test it and reload the web
          server — noticeably slower than a form usually is, so the wait is
          explained rather than left to read as a hang. */}
      {saving && savingNote ? (
        <p className="mr-auto text-xs text-muted-foreground">{savingNote}</p>
      ) : null}
      {!saving && dirty && showUnsaved ? (
        <Badge variant="warning" className="mr-auto">
          {t("unsaved")}
        </Badge>
      ) : null}
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
        >
          {saving ? <Loader2 className="size-4 animate-spin" /> : null}
          {saving ? t("saving") : (saveLabel ?? t("save"))}
        </Button>
      </ReasonTooltip>
    </div>
  );
}
