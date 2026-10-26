"use client";

import { useState } from "react";
import { useRefresh } from "@/hooks/use-refresh";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { CalendarClock, Loader2, Settings2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { deleteCleanerSchedule, saveCleanerSchedule } from "@/lib/api/disk-cleaner";
import { clockTimeOf } from "@/lib/disk-cleaner/next-run";
import { scheduleTimeLabel } from "@/lib/backups/schedule-time";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Switch } from "@/components/ui/switch";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import { FormModal } from "@/components/ui/form-modal";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Tile } from "@/components/applications/app-status-tiles";
import { Caution } from "@/components/ui/caution";
import { KeepSelect, useKeepLabel } from "@/components/disk-cleaner/keep-select";
import { keepOptions, sizeFor } from "@/lib/disk-cleaner/keep-days";

const FREQUENCIES = ["hourly", "daily", "weekly", "monthly"];

// Radix Select cannot hold "", which is how the form spells "every time".
const ANY = "any";
const THRESHOLDS = ["50", "60", "70", "80", "90"];
// Suggested beside Delete all: clear every log only when the disk is actually filling up.
const DELETE_ALL_THRESHOLD = "85";

// A schedule saved with another number (the API takes 1–99) keeps it on offer.
function thresholdOptions(current) {
  return current && !THRESHOLDS.includes(current)
    ? [...THRESHOLDS, current].sort((a, b) => Number(a) - Number(b))
    : THRESHOLDS;
}

// Only `safe` categories are offered; the backend enforces the same.
export function ScheduleCard({ schedule, categories, keepDays, canManage }) {
  const t = useTranslations("diskCleaner");
  const keepLabel = useKeepLabel();
  const { choices, recommended } = keepOptions(keepDays);
  // A schedule saved before the choice keeps `null` ("as before") until someone picks one.
  const savedKeep = () => (schedule ? (schedule.keep_days ?? null) : recommended);
  // The hour uses the reader's clock convention (AM/PM vs 24h) but never their
  // timezone, same as a backup's schedule time.
  const format = useFormatter();
  const { refreshAndWait } = useRefresh();
  const [open, setOpen] = useState(false);
  const [pending, setPending] = useState(false);

  const [keep, setKeep] = useState(savedKeep);
  // Biggest first under the chosen keep, the same order as the list on the page.
  const safeCategories = categories
    .filter((c) => c.available && c.safe)
    .map((c) => ({ ...c, size: sizeFor(c, keep) }))
    .sort((a, b) => b.size.bytes - a.size.bytes);
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
    setKeep(savedKeep());
  }

  async function save() {
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
        // null keeps the old per-category behaviour; 0 (delete all) is allowed here.
        keep_days: keep,
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

  // The threshold is the other half of when this fires; "every time" goes without saying.
  const whenLine =
    schedule?.enabled && schedule?.threshold_percent != null
      ? t("schedule.overThreshold", { percent: schedule.threshold_percent })
      : null;

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

  // An icon, not a text button: the words squeezed the tile's own text onto three
  // lines in German and cut off the next run.
  const configureButton = (
    <Button
      variant="outline"
      size="icon"
      className="size-8 shrink-0"
      disabled={!canManage}
      aria-label={t("schedule.setUp")}
      onClick={() => {
        reset();
        setOpen(true);
      }}
    >
      <Settings2 className="size-4" />
    </Button>
  );

  return (
    <>
      {/* A compact tile like the two beside it. Which items it cleans is in Configure. */}
      <Tile
        compact
        icon={CalendarClock}
        label={t("schedule.title")}
        value={summary}
        // Shows whether a schedule that looks on has ever fired, and when it fires next.
        // The next run is null while the cleaner is off: the API names no run that will
        // not happen, and the tile must not invent one.
        detail={[
          nextRunLine ??
            (schedule?.last_run_at_human
              ? t("schedule.lastRun", { when: schedule.last_run_at_human })
              : t("schedule.neverRun")),
          whenLine,
          schedule?.enabled ? keepLabel(schedule.keep_days ?? null) : null,
        ]
          .filter(Boolean)
          .join(" · ")}
        tone={schedule?.enabled ? "success" : "neutral"}
        action={
          canManage ? (
            // Named on hover, like the refresh buttons: an icon alone does not say what it opens.
            <Tooltip>
              <TooltipTrigger asChild>{configureButton}</TooltipTrigger>
              <TooltipContent>{t("schedule.setUp")}</TooltipContent>
            </Tooltip>
          ) : (
            <ReasonTooltip reason={t("noPermission")}>{configureButton}</ReasonTooltip>
          )
        }
      />

      <FormModal
        open={open}
        onOpenChange={(next) => !pending && setOpen(next)}
        icon={CalendarClock}
        title={t("schedule.title")}
        description={t("schedule.dialogDescription")}
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
              <Button type="submit" disabled={pending || (enabled && picked.size === 0)}>
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

          {/* Off, the settings below are hidden rather than greyed out, so the dialog
              stays short; picked items are kept and come back when it is turned on. */}
          {!enabled ? (
            <p className="-mt-3 text-xs text-muted-foreground">
              {t("schedule.turnOnFirst")}
            </p>
          ) : (
            <>
              <div className="grid gap-4 sm:grid-cols-2">
                <div className="space-y-2">
                  <Label htmlFor="cleaner-frequency">{t("schedule.howOften")}</Label>
                  <Select value={frequency} onValueChange={setFrequency}>
                    <SelectTrigger id="cleaner-frequency" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      {FREQUENCIES.map((value) => (
                        <SelectItem key={value} value={value}>
                          {t(`schedule.frequencyOption.${value}`)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>

                {/* A list, not a number box: an example "80" in an empty box read as a
                    value already set, while empty really meant "every time". */}
                <div className="space-y-2">
                  <Label htmlFor="cleaner-threshold" hint={t("schedule.onlyWhenHint")}>
                    {t("schedule.onlyWhen")}
                  </Label>
                  <Select
                    value={threshold === "" ? ANY : threshold}
                    onValueChange={(value) => setThreshold(value === ANY ? "" : value)}
                  >
                    <SelectTrigger id="cleaner-threshold" className="w-full">
                      <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                      <SelectItem value={ANY}>{t("schedule.thresholdAny")}</SelectItem>
                      {thresholdOptions(threshold).map((value) => (
                        <SelectItem key={value} value={value}>
                          {t("schedule.thresholdOver", { percent: Number(value) })}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </div>
              </div>

              <div className="space-y-2">
                <Label htmlFor="cleaner-keep" hint={t("keep.hint")}>
                  {t("keep.label")}
                </Label>
                <KeepSelect
                  id="cleaner-keep"
                  value={keep}
                  onChange={setKeep}
                  choices={choices}
                  recommended={recommended}
                  allowDefault={schedule != null && schedule.keep_days == null}
                  className="w-full"
                />
                {/* A suggestion, not a rule: the API accepts Delete all at any threshold. */}
                {keep === 0 && threshold !== DELETE_ALL_THRESHOLD ? (
                  <Caution
                    size="md"
                    action={
                      <Button type="button" size="sm" variant="outline" className="h-8" onClick={() => setThreshold(DELETE_ALL_THRESHOLD)}>
                        {t("keep.useThreshold", { percent: Number(DELETE_ALL_THRESHOLD) })}
                      </Button>
                    }
                  >
                    <p>{t("keep.scheduleDeleteAllHint", { percent: Number(DELETE_ALL_THRESHOLD) })}</p>
                  </Caution>
                ) : null}
              </div>

              <div className="space-y-2">
                <Label className="flex flex-wrap items-center justify-between gap-2">
                  <span>{t("schedule.whatToClean")}</span>
                  {/* Inline, not in a tooltip: Save stays disabled until something is ticked. */}
                  {picked.size === 0 ? (
                    <span className="font-normal leading-snug text-muted-foreground">{t("schedule.pickSomething")}</span>
                  ) : null}
                </Label>
                <ul className="divide-y overflow-hidden rounded-lg border">
                  {safeCategories.map((category) => {
                    const checked = picked.has(category.key);
                    return (
                      <li key={category.key}>
                        {/* The whole row is the label, as on the page. */}
                        <label
                          htmlFor={`schedule-${category.key}`}
                          className={cn(
                            "flex cursor-pointer items-center gap-3 px-3 py-2.5 transition-colors hover:bg-muted/40",
                            checked && "bg-primary/5 hover:bg-primary/5",
                          )}
                        >
                          <Checkbox
                            id={`schedule-${category.key}`}
                            checked={checked}
                            onCheckedChange={() =>
                              setPicked((prev) => {
                                const next = new Set(prev);
                                if (next.has(category.key)) next.delete(category.key);
                                else next.add(category.key);
                                return next;
                              })
                            }
                          />
                          <span className="min-w-0 flex-1 text-sm">{category.label}</span>
                          <span className="shrink-0 text-sm tabular-nums text-muted-foreground">
                            {category.size.human ?? "0 B"}
                          </span>
                        </label>
                      </li>
                    );
                  })}
                </ul>
                <p className="text-xs text-muted-foreground">{t("schedule.safeOnly")}</p>
              </div>
            </>
          )}
        </div>
      </FormModal>
    </>
  );
}
