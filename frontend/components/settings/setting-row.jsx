"use client";

import { useTranslations } from "next-intl";
import { Loader2, Lock } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { FormItem, FormLabel, FormMessage } from "@/components/ui/form";
import { useWatchUnsaved } from "@/components/settings/unsaved-guard";

/**
 * One settings row: name and helper text on the left, the control on the right.
 *
 * The control column is a FIXED width and its contents are left-aligned, so a
 * switch, a time input and a select all begin on the same vertical line. Right-
 * aligning them instead lines up their trailing edges, which for controls of
 * different widths is no alignment you can actually see.
 */
const ROW =
  "grid gap-x-8 gap-y-2 py-3.5 sm:grid-cols-[minmax(0,1fr)_14rem] sm:items-center";

const ROW_WIDE = "flex flex-col gap-2 py-3.5";

function RowLabel({ as: As, label, hint, required }) {
  return (
    <div className="min-w-0 space-y-1">
      <As className="text-sm font-medium" required={required}>
        {label}
      </As>
      {hint ? (
        <p className="text-xs leading-relaxed text-muted-foreground">{hint}</p>
      ) : null}
    </div>
  );
}

export function Row({ label, hint, error, children, className, wide = false, required = false }) {
  return (
    <FormItem className={cn(wide ? ROW_WIDE : ROW, className)}>
      <RowLabel as={FormLabel} label={label} hint={hint} required={required} />
      <div className="space-y-1.5">
        {children}
        <FormMessage>{error}</FormMessage>
      </div>
    </FormItem>
  );
}

/** A row that reports rather than edits, so it must not claim a form label. */
export function InfoRow({ label, hint, children, className }) {
  return (
    <div className={cn(ROW, className)}>
      <RowLabel as="p" label={label} hint={hint} />
      <div>{children}</div>
    </div>
  );
}

/**
 * One settings group as its own card: header band, rows, action band.
 *
 * A card each rather than three sections in one, because each group commits on
 * its own — the card boundary is what makes "this button saves these rows"
 * true at a glance rather than something you have to work out from spacing.
 */
export function Section({
  icon: Icon,
  title,
  description,
  tone,
  readOnly,
  actions,
  changedBy,
  children,
}) {
  const t = useTranslations("settings.common");
  const destructive = tone === "destructive";

  return (
    <Card className="gap-0 overflow-hidden py-0 shadow-sm">
      {/* Skipped entirely (not just emptied) when there's no title — a page
          with exactly one card already names it in the page's own h1, and an
          empty header band would just be dead space with a stray border. */}
      {title ? (
        <div className="flex items-center gap-2.5 border-b px-5 py-3.5">
          {Icon ? (
            <span
              className={cn(
                "flex size-7 shrink-0 items-center justify-center rounded-md",
                destructive
                  ? "bg-destructive/10 text-destructive"
                  : "bg-primary/10 text-primary",
              )}
            >
              <Icon className="size-3.5" />
            </span>
          ) : null}
          <div className="min-w-0">
            <h3
              className={cn(
                "text-base font-semibold tracking-tight",
                destructive && "text-destructive",
              )}
            >
              {title}
            </h3>
            {description ? (
              <p className="text-sm text-muted-foreground">{description}</p>
            ) : null}
          </div>
        </div>
      ) : null}

      <CardContent className="px-5 py-2">
        {readOnly ? (
          <p className="mt-3.5 flex w-fit items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
            <Lock className="size-3.5 shrink-0" />
            {t("readOnly")}
          </p>
        ) : null}
        {children}
      </CardContent>

      {/* Its own band, so the button that saves these rows sits inside the same
          box as the rows it saves. */}
      {actions ? (
        <div className="flex flex-wrap items-center justify-end gap-2 border-t px-5 py-2.5">
          {/* Opposite the Save button, because it answers the question that
              button raises on a shared server: who changed this last. */}
          {changedBy ? (
            <p className="mr-auto text-xs text-muted-foreground">{changedBy}</p>
          ) : null}
          {actions}
        </div>
      ) : null}
    </Card>
  );
}

/** A section's action area: Discard only when there is something to lose. */
export function SectionActions({
  label,
  isDirty,
  pending,
  onDiscard,
  canManage,
}) {
  const t = useTranslations("settings.common");
  // Named by its own Save label — unique per card, and already translated.
  useWatchUnsaved(label, isDirty);

  // Whatever is standing in the way, or null once the button is live. Ordered
  // by which one the user can do something about.
  const reason = !canManage ? t("readOnly") : !isDirty ? t("nothingToSave") : null;

  return (
    <>
      {isDirty ? (
        <Button
          type="button"
          variant="ghost"
          onClick={onDiscard}
          disabled={pending}
        >
          {t("discard")}
        </Button>
      ) : null}

      {/* Disabled and explained, rather than restyled. A button that quietly
          swaps variant says it is in some other state but never which, or why. */}
      <ReasonTooltip reason={reason}>
        <Button type="submit" disabled={Boolean(reason) || pending}>
          {pending && <Loader2 className="size-4 animate-spin" />}
          {pending ? t("saving") : label}
        </Button>
      </ReasonTooltip>
    </>
  );
}
