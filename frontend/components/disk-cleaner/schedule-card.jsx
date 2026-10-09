"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { CalendarClock, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { deleteCleanerSchedule, saveCleanerSchedule } from "@/lib/api/disk-cleaner";
import { clampPercent, thresholdProblem } from "@/lib/disk-cleaner/clamp-percent";
import { clockTimeOf } from "@/lib/disk-cleaner/next-run";
import { scheduleTimeLabel } from "@/lib/backups/schedule-time";
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

// Only `safe` categories are offered; the backend enforces the same.
export function ScheduleCard({ schedule, categories, canManage }) {
  const t = useTranslations("diskCleaner");
  // The hour uses the reader's clock convention (AM/PM vs 24h) but never their
  // timezone, same as a backup's schedule time.
  const format = useFormatter();
  const { refreshAndWait } = useRefresh();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);

  const safeCategories = categories.filter((c) => c.available && c.safe);
  const [enabled, setEnabled] = useState(Boolean(schedule?.enabled));
  const [frequency, setFrequency] = useState(schedule?.frequency ?? "weekly");
  const [picked, setPicked] = useState(() => new Set(schedule?.categories ?? []));
  const [threshold, setThreshold] = useState(
    schedule?.threshold_percent != null ? String(schedule.threshold_percent) : "",
  );

  // Reopening after a cancel shows what is saved, not the last half-typed state.
  function reset() {
    setEnabled(Boolean(schedule?.enabled));
    setFrequency(schedule?.frequency ?? "weekly");
    setPicked(new Set(schedule?.categories ?? []));
    setThreshold(schedule?.threshold_percent != null ? String(schedule.threshold_percent) : "");
  }

  const thresholdInvalid = enabled && thresholdProblem(threshold) !== null;

  async function save() {
    if (thresholdInvalid) return;
    setPending(true);
    try {
      // Off with nothing ticked DELETES the schedule: the API requires a category on
      // every save. With categories ticked it is only paused.
      if (!enabled && picked.size === 0) {
        await deleteCleanerSchedule();
        await refreshAndWait();
        toast.success(t("schedule.saved"));
        setOpen(false);
        return;
      }

      await saveCleanerSchedule({
        enabled,
        frequency,
        categories: [...picked],
        // Empty means "always", which the API expresses as null.
        threshold_percent: threshold === "" ? null : Number(threshold),
        // `notify` is an unused column; sent back as stored so this form never
        // rewrites a field it does not own.
        notify: schedule?.notify ?? false,
      });
      await refreshAndWait();
      toast.success(t("schedule.saved"));
      setOpen(false);
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

  // Names, not a count; the threshold is the other half of when this fires.
  const scheduledLabels = (schedule?.categories ?? [])
    .map((key) => categories.find((c) => c.key === key)?.label)
    .filter(Boolean);

  const whenLine =
    schedule?.threshold_percent != null
      ? t("schedule.overThreshold", { percent: schedule.threshold_percent })
      : t("schedule.everyTime");

  // Only the API knows the hour; the zone is named because it is the project's, not the reader's.
  const nextClockTime = clockTimeOf(schedule?.next_run_at);
  const nextRunLine =
    schedule?.enabled && schedule?.next_run_at_human
      ? nextClockTime && schedule?.timezone
        ? t("schedule.nextRunAtZone", {
            when: schedule.next_run_at_human,
            time: scheduleTimeLabel(nextClockTime, format),
            timezone: schedule.timezone,
          })
        : t("schedule.nextRun", { when: schedule.next_run_at_human })
      : null;

  return (
    <>
      <Card className="gap-0 overflow-hidden py-0">
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

          {/* One badge per item; names in a sentence blur together at 12px. */}
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
          ) : null}

          <div className="mt-2 flex items-center justify-between gap-3">
            <div className="min-w-0">
              {/* Shows whether a schedule that looks on has ever fired. */}
              <p className="truncate text-xs text-muted-foreground">
                {schedule?.last_run_at_human
                  ? t("schedule.lastRun", { when: schedule.last_run_at_human })
                  : t("schedule.neverRun")}
              </p>
              {/* Null while the cleaner is off: the API names no run that will
                  not happen, and the card must not invent one. */}
              {nextRunLine ? (
                <p className="truncate text-xs text-muted-foreground">{nextRunLine}</p>
              ) : null}
            </div>

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
        // A form, so Enter in the threshold field saves like every other dialog.
        asForm
        onSubmit={(event) => {
          event.preventDefault();
          save();
        }}
        footer={
          <>
            <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={pending}>
              {t("confirm.cancel")}
            </Button>
            <ReasonTooltip
              reason={enabled && picked.size === 0 ? t("schedule.pickSomething") : null}
            >
              {/* Spinner and label while saving: the dialog stays open, so a
                  greyed-out button alone reads as a missed click. */}
              <Button type="submit" disabled={pending || (enabled && picked.size === 0) || thresholdInvalid}>
                {pending ? <Loader2 className="size-4 animate-spin" /> : null}
                {pending ? t("schedule.saving") : t("schedule.save")}
              </Button>
            </ReasonTooltip>
          </>
        }
      >
        <div className="space-y-5">
          <div className="flex items-center justify-between gap-4">
            <Label htmlFor="cleaner-enabled" className="font-normal" hint={t("schedule.enableHint")}>
              {t("schedule.enable")}
            </Label>
            <Switch id="cleaner-enabled" checked={enabled} onCheckedChange={setEnabled} />
          </div>

          {/* Stated inline, not only in a tooltip: the controls below are
              disabled while off, and Radix tooltips never open on touch. */}
          {!enabled ? (
            <p className="-mt-3 text-xs text-muted-foreground">
              {t("schedule.turnOnFirst")}
            </p>
          ) : null}

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
              <Label htmlFor="cleaner-threshold" hint={t("schedule.onlyWhenHint")}>{t("schedule.onlyWhen")}</Label>
              <div className="relative">
                <Input
                  id="cleaner-threshold"
                  inputMode="numeric"
                  className="pr-8"
                  value={threshold}
                  disabled={!enabled}
                  disabledReason={t("schedule.turnOnFirst")}
                  // An example number, not a word: it reads under the label.
                  placeholder={t("schedule.thresholdPlaceholder")}
                  onChange={(e) => setThreshold(clampPercent(e.target.value))}
                  aria-invalid={thresholdInvalid || undefined}
                  aria-describedby={thresholdInvalid ? "cleaner-threshold-error" : undefined}
                />
                {/* Always rendered: the unit is what an empty box must show. */}
                <span className="pointer-events-none absolute inset-y-0 right-3 flex items-center text-sm text-muted-foreground">
                  %
                </span>
              </div>
              {thresholdInvalid ? (
                <p id="cleaner-threshold-error" role="alert" className="text-sm text-destructive">
                  {t("schedule.thresholdRange")}
                </p>
              ) : (
                <p className="text-xs text-muted-foreground">{t("schedule.thresholdHint")}</p>
              )}
            </div>
          </div>

          <div className={cn("space-y-2", !enabled && "opacity-50")}>
            <Label className="flex flex-wrap items-center justify-between gap-2">
              <span>{t("schedule.whatToClean")}</span>
              {/* Inline, not in a tooltip: Save stays disabled until something is ticked. */}
              {enabled && picked.size === 0 ? (
                <span className="font-normal text-warning">{t("schedule.pickSomething")}</span>
              ) : null}
            </Label>
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
