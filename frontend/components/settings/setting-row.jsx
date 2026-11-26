import { HeadIcon } from "@/components/ui/head-icon";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { FormItem, FormLabel, FormMessage } from "@/components/ui/form";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";

const ROW = "py-0";

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

export function InfoRow({ label, hint, children, className }) {
  return (
    <div data-setting-row="" className={cn("grid content-start gap-2", className)}>
      <RowLabel as="p" label={label} hint={hint} />
      <div>{children}</div>
    </div>
  );
}

export function Section({
  icon: Icon,
  title,
  description,
  tone,
  badge,
  readOnly,
  actions,
  headerAction,
  changedBy,
  gridClassName,
  children,
}) {
  const destructive = tone === "destructive";
  const showActions = Boolean(actions) && !readOnly && !(actions.type === SectionActions && actions.props.canManage === false);

  return (
    <Card className="@container/section gap-0 overflow-hidden py-0">
      {title ? (
        <div className={cn("flex min-w-0 gap-3 border-b bg-[var(--card-head)] px-5 py-4", description ? "items-start" : "items-center")}>
          <HeadIcon icon={Icon} tone={destructive ? "destructive" : "default"} />
          <div className="min-w-0 flex-1">
            <h3 className="flex flex-wrap items-center gap-2 text-[15px] font-semibold tracking-tight">
              {title}
              {badge}
            </h3>
            {description ? (
              <p className="mt-0.5 text-sm text-muted-foreground">{description}</p>
            ) : null}
          </div>
          {headerAction ? <div className="shrink-0 self-center">{headerAction}</div> : null}
        </div>
      ) : null}

      <CardContent
        className={cn(
          "grid gap-x-6 gap-y-5 px-5 py-5 @3xl/section:grid-cols-2 [&>:not([data-setting-row])]:col-span-full",
          gridClassName,
        )}
      >
        {children}
      </CardContent>

      {showActions ? (
        <div className="flex flex-wrap items-center justify-end gap-2 border-t bg-muted/30 px-5 py-3">
          {changedBy ? (
            <p className="mr-auto text-xs text-muted-foreground">{changedBy}</p>
          ) : null}
          {actions}
        </div>
      ) : null}
    </Card>
  );
}

export function SectionActions({
  label,
  isDirty,
  pending,
  onDiscard,
  canManage,
  blockedReason = null,
}) {
  const t = useTranslations("settings.common");
  useWatchUnsaved(label, isDirty);

  const reason = blockedReason ?? (!isDirty ? t("nothingToSave") : null);

  if (!canManage) return null;

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

      <ReasonTooltip reason={reason}>
        <Button type="submit" disabled={Boolean(reason) || pending}>
          {pending && <Loader2 className="size-4 animate-spin" />}
          {pending ? t("saving") : label}
        </Button>
      </ReasonTooltip>
    </>
  );
}
