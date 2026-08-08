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
  // Said while the change is still unsaved, for a save whose cost is not
  // obvious from the button — PHP settings reload the site's FPM pool.
  note,
  // Prints `saveReason` next to the disabled button instead of leaving it in
  // the tooltip. Worth it on a long form, where "why is Save grey" is asked
  // often enough that it should not need a hover to answer.
  showReason = false,
  // Greys the button while there is nothing to save, instead of leaving a
  // faded primary that still reads as the thing to press. The settings cards
  // deliberately do NOT do this — only forms that opt in.
  quietWhenClean = false,
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
      {/* Discard and save stay on one line together — split across two rows
          they read as two separate decisions. */}
      <div className="flex shrink-0 items-center gap-2">
        {dirty && onDiscard ? (
          <Button type="button" variant="ghost" onClick={onDiscard} disabled={saving}>
            {t("discard")}
          </Button>
        ) : null}
        <ReasonTooltip reason={saveReason}>
          <Button
            type={submit ? "submit" : "button"}
            variant={quietWhenClean && !dirty && !saving ? "secondary" : "default"}
            onClick={submit ? undefined : onSave}
            disabled={Boolean(saveReason) || saving}
          >
            {saving ? <Loader2 className="size-4 animate-spin" /> : null}
            {saving ? t("saving") : (saveLabel ?? t("save"))}
          </Button>
        </ReasonTooltip>
      </div>
    </div>
  );
}
