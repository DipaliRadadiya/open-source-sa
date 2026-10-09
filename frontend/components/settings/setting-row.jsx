import { useTranslations } from "next-intl";
import { Loader2, Lock } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { FormItem, FormLabel, FormMessage } from "@/components/ui/form";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";

// Fields sit in a two-column grid with the label above the control, as on the PHP
// and Create pages. Label left / control right across a full-width card left a
// gap of several hundred pixels between a question and its answer.
const ROW = "py-0";

// A switch is a yes/no answer to its own sentence, so it keeps the sentence
// beside it, in a tile that holds the pair together.
const TOGGLE =
  "flex items-start justify-between gap-4 rounded-xl border bg-muted/20 p-4 dark:bg-input/20";

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

// `data-setting-row` marks grid cells; anything else in a Section spans the full width.
export function Row({ label, hint, error, children, className, wide = false, toggle = false, required = false }) {
  if (toggle) {
    return (
      <FormItem data-setting-row="" className={cn(TOGGLE, wide && "col-span-full", className)}>
        <RowLabel as={FormLabel} label={label} hint={hint} required={required} />
        <div className="shrink-0 pt-0.5">
          {children}
          <FormMessage>{error}</FormMessage>
        </div>
      </FormItem>
    );
  }
  return (
    <FormItem data-setting-row="" className={cn(ROW, wide && "col-span-full", className)}>
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
    <div data-setting-row="" className={cn("grid content-start gap-2", className)}>
      <RowLabel as="p" label={label} hint={hint} />
      <div>{children}</div>
    </div>
  );
}

// Each group saves on its own, so the card boundary shows what a Save covers.
export function Section({
  title,
  description,
  tone,
  badge,
  readOnly,
  actions,
  changedBy,
  // Overrides the two-column field grid when a card's fields want another split.
  gridClassName,
  children,
}) {
  const t = useTranslations("settings.common");
  const destructive = tone === "destructive";

  return (
    <Card className="@container/section gap-0 overflow-hidden py-0">
      {/* The same head as the cards on an application's page: title and line on a
          band of their own. No icon: the tab above already carries one. */}
      {title ? (
        <div className="min-w-0 border-b px-5 py-4">
          <h3
            className={cn(
              "flex flex-wrap items-center gap-2 text-base font-semibold tracking-tight",
              destructive && "text-destructive",
            )}
          >
            {title}
            {badge}
          </h3>
          {description ? (
            <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
          ) : null}
        </div>
      ) : null}

      <CardContent
        className={cn(
          "grid gap-x-6 gap-y-5 px-5 py-5 @3xl/section:grid-cols-2 [&>:not([data-setting-row])]:col-span-full",
          gridClassName,
        )}
      >
        {readOnly ? (
          <p className="flex w-fit items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
            <Lock className="size-3.5 shrink-0" />
            {t("readOnly")}
          </p>
        ) : null}
        {children}
      </CardContent>

      {/* Its own band, so the button that saves these rows sits inside the same
          box as the rows it saves. */}
      {actions ? (
        <div className="flex flex-wrap items-center justify-end gap-2 border-t bg-muted/30 px-5 py-3">
          {/* Opposite Save: who changed this last, on a shared server. */}
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

      {/* Disabled and explained, rather than restyled. */}
      <ReasonTooltip reason={reason}>
        <Button type="submit" disabled={Boolean(reason) || pending}>
          {pending && <Loader2 className="size-4 animate-spin" />}
          {pending ? t("saving") : label}
        </Button>
      </ReasonTooltip>
    </>
  );
}
