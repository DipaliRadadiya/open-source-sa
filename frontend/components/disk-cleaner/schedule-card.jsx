"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { CalendarClock, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { saveCleanerSchedule } from "@/lib/api/disk-cleaner";
import { apiMessage } from "@/lib/api/error-message";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { Input } from "@/components/ui/input";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { FormModal } from "@/components/ui/form-modal";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import {
  Card,
  CardContent,
} from "@/components/ui/card";

const FREQUENCIES = ["hourly", "daily", "weekly", "monthly"];

/**
 * Unattended cleanup: a summary on the page, the settings in a dialog.
 *
 * It expanded inline at first, which meant the card grew inside a two-column
 * row and left a tall hole beside it — and any fix for that (stretching the
 * neighbour, moving it to its own row) traded one empty area for another. A
 * dialog can't distort the page it opens from, and the settings get more room
 * than a half-width column ever had.
 *
 * Only `safe` categories are offered. The backend enforces that too, but a
 * control that exists to be rejected is worse than no control.
 */
export function ScheduleCard({ schedule, categories, canManage }) {
  const t = useTranslations("diskCleaner");
  const router = useRouter();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);

  const safeCategories = categories.filter((c) => c.available && c.safe);
  const [enabled, setEnabled] = useState(Boolean(schedule?.enabled));
  const [frequency, setFrequency] = useState(schedule?.frequency ?? "weekly");
  const [picked, setPicked] = useState(() => new Set(schedule?.categories ?? []));
  const [threshold, setThreshold] = useState(
    schedule?.threshold_percent != null ? String(schedule.threshold_percent) : "",
  );

  // Reopening after a cancel should show what is actually saved, not whatever
  // was half-typed last time.
  function reset() {
    setEnabled(Boolean(schedule?.enabled));
    setFrequency(schedule?.frequency ?? "weekly");
    setPicked(new Set(schedule?.categories ?? []));
    setThreshold(schedule?.threshold_percent != null ? String(schedule.threshold_percent) : "");
  }

  async function save() {
    setPending(true);
    try {
      await saveCleanerSchedule({
        enabled,
        frequency,
        categories: [...picked],
        // Empty means "always", which the API expresses as null — an empty
        // string would fail validation for something the user never typed.
        threshold_percent: threshold === "" ? null : Number(threshold),
        // Confirmed with the backend team (2026-08-01): `notify` is an unused
        // column. Nothing reads it, so it gets no control — sent back as stored
        // purely so this form never rewrites a field it does not own.
        notify: schedule?.notify ?? false,
      });
      toast.success(t("schedule.saved"));
      setOpen(false);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("schedule.failed")));
    } finally {
      setPending(false);
    }
  }

  const summary = schedule?.enabled
    ? t("schedule.summaryOn", {
        frequency: t(`schedule.frequency.${schedule.frequency ?? "weekly"}`),
        count: schedule.categories?.length ?? 0,
      })
    : t("schedule.summaryOff");

  // Names, not a count: "1 item" tells you nothing about whether the right
  // thing is scheduled. The threshold joins it because "over 80%" is the other
  // half of when this fires.
  const scheduledLabels = (schedule?.categories ?? [])
    .map((key) => categories.find((c) => c.key === key)?.label)
    .filter(Boolean);

  const whenLine =
    schedule?.threshold_percent != null
      ? t("schedule.overThreshold", { percent: schedule.threshold_percent })
      : t("schedule.everyTime");

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0 shadow-sm">
        <CardContent className="px-4 py-3.5">
          <div className="flex items-center gap-2">
            <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-muted text-muted-foreground">
              <CalendarClock className="size-3.5" />
            </span>
            <span className="text-sm font-medium text-muted-foreground">
              {t("schedule.title")}
            </span>
            {schedule?.enabled ? (
              <Badge variant="success" className="ml-auto font-normal">
                {t("schedule.on")}
              </Badge>
            ) : null}
          </div>

          <div className="mt-4 flex items-baseline justify-between gap-2">
            <p className="text-lg font-semibold leading-none tracking-tight">{summary}</p>
          </div>

          {/* One badge per item. Run together in a sentence the names blurred
              into each other — "Temporary files, Service logs" reads as one
              thing at 12px. */}
          {schedule?.enabled ? (
            <div className="mt-2.5 flex flex-wrap items-center gap-1.5">
              {scheduledLabels.length ? (
                scheduledLabels.map((label) => (
                  <Badge key={label} variant="outline" className="font-normal text-muted-foreground">
                    {label}
                  </Badge>
                ))
              ) : (
                <span className="text-xs text-muted-foreground">
                  {t("schedule.cleansNothing")}
                </span>
              )}
              <span className="text-xs text-muted-foreground">{whenLine}</span>
            </div>
          ) : (
            <p className="mt-2 text-xs text-muted-foreground">{t("schedule.summaryOff")}</p>
          )}

          <div className="mt-2 flex items-center justify-between gap-3">
            {/* "Has it ever actually run" is the question that catches a
                schedule which looks on but never fires. */}
            <p className="truncate text-xs text-muted-foreground">
              {schedule?.last_run_at_human
                ? t("schedule.lastRun", { when: schedule.last_run_at_human })
                : t("schedule.neverRun")}
            </p>

            <ReasonTooltip reason={canManage ? null : t("noPermission")}>
              <Button
                variant="outline"
                size="sm"
                className="h-7 shrink-0"
                disabled={!canManage}
                onClick={() => {
                  reset();
                  setOpen(true);
                }}
              >
                {t("schedule.configure")}
              </Button>
            </ReasonTooltip>
          </div>
        </CardContent>
      </Card>

      <FormModal
        open={open}
        onOpenChange={(next) => !pending && setOpen(next)}
        icon={CalendarClock}
        title={t("schedule.title")}
        description={t("schedule.dialogDescription")}
        footer={
          <>
            <Button variant="outline" onClick={() => setOpen(false)} disabled={pending}>
              {t("confirm.cancel")}
            </Button>
            <ReasonTooltip
              reason={enabled && picked.size === 0 ? t("schedule.pickSomething") : null}
            >
              {/* Disabled is not feedback: the dialog stays open while the
                  write happens, so a button that only greys out reads as a
                  click that did not land. Same spinner-and-label as every
                  other save in the panel. */}
              <Button onClick={save} disabled={pending || (enabled && picked.size === 0)}>
                {pending ? <Loader2 className="size-4 animate-spin" /> : null}
                {pending ? t("schedule.saving") : t("schedule.save")}
              </Button>
            </ReasonTooltip>
          </>
        }
      >
        <div className="space-y-5">
          <div className="flex items-center justify-between gap-4">
            <Label htmlFor="cleaner-enabled" className="font-normal">
              {t("schedule.enable")}
            </Label>
            <Switch id="cleaner-enabled" checked={enabled} onCheckedChange={setEnabled} />
          </div>

          <div className={cn("grid gap-4 sm:grid-cols-2", !enabled && "opacity-50")}>
            <div className="space-y-2">
              <Label htmlFor="cleaner-frequency">{t("schedule.howOften")}</Label>
              <ReasonTooltip
                reason={enabled ? null : t("schedule.turnOnFirst")}
                className="block w-full"
              >
              <Select value={frequency} onValueChange={setFrequency} disabled={!enabled}>
                <SelectTrigger id="cleaner-frequency" className="w-full">
                  <SelectValue />
                </SelectTrigger>
                <SelectContent>
                  {FREQUENCIES.map((value) => (
                    <SelectItem key={value} value={value}>
                      {t(`schedule.frequency.${value}`)}
                    </SelectItem>
                  ))}
                </SelectContent>
              </Select>
              </ReasonTooltip>
            </div>

            <div className="space-y-2">
              <Label htmlFor="cleaner-threshold">{t("schedule.onlyWhen")}</Label>
              <div className="relative">
                <Input
                  id="cleaner-threshold"
                  inputMode="numeric"
                  className="pr-8"
                  value={threshold}
                  disabled={!enabled}
                  disabledReason={t("schedule.turnOnFirst")}
                  placeholder={t("schedule.always")}
                  onChange={(e) => setThreshold(e.target.value.replace(/[^0-9]/g, "").slice(0, 3))}
                />
                {threshold ? (
                  <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
                    %
                  </span>
                ) : null}
              </div>
              <p className="text-xs text-muted-foreground">{t("schedule.thresholdHint")}</p>
            </div>
          </div>

          <div className={cn("space-y-2", !enabled && "opacity-50")}>
            <Label>{t("schedule.whatToClean")}</Label>
            <ul className="divide-y rounded-lg border">
              {safeCategories.map((category) => (
                <li key={category.key} className="flex items-center gap-3 px-3 py-2.5">
                  <Checkbox
                    checked={picked.has(category.key)}
                    disabled={!enabled}
                    disabledReason={t("schedule.turnOnFirst")}
                    onCheckedChange={() =>
                      setPicked((prev) => {
                        const next = new Set(prev);
                        if (next.has(category.key)) next.delete(category.key);
                        else next.add(category.key);
                        return next;
                      })
                    }
                    aria-label={category.label}
                  />
                  <span className="flex-1 text-sm">{category.label}</span>
                  <span className="text-sm tabular-nums text-muted-foreground">
                    {category.reclaimable_human ?? "0 B"}
                  </span>
                </li>
              ))}
            </ul>
            <p className="text-xs text-muted-foreground">{t("schedule.safeOnly")}</p>
          </div>

        </div>
      </FormModal>
    </>
  );
}
