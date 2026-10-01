"use client";

import { useFormatter, useNow, useTranslations } from "next-intl";
import { CalendarClock, TriangleAlert } from "lucide-react";
import { describeCron, nextRuns, parseCron, wallClockNow } from "@/lib/cron-jobs/schedule";

// Dates built from wall-clock fields are formatted in UTC so nothing shifts
// them into the reader's own zone; the times are the server's.
const UTC = { timeZone: "UTC" };

/**
 * The schedule in words when it can be said in a sentence, and always the
 * next three run times, which are exact either way.
 */
export function useScheduleText(expression, timezone) {
  const t = useTranslations("cronJobs.preview");
  const format = useFormatter();
  const now = useNow({ updateInterval: 60000 });

  const cron = parseCron(expression);
  if (!cron) return null;

  const time = ([h, m]) =>
    // Kept whole so "12:00" and "AM" never land on different lines.
    format.dateTime(new Date(Date.UTC(2000, 0, 1, h, m)), { ...UTC, hour: "numeric", minute: "2-digit" }).replace(/\s/g, "\u00a0");
  // 2 January 2000 was a Sunday, so day d of that week is weekday d.
  const weekday = (d) => format.dateTime(new Date(Date.UTC(2000, 0, 2 + d)), { ...UTC, weekday: "long" });
  const month = (m) => format.dateTime(new Date(Date.UTC(2000, m - 1, 1)), { ...UTC, month: "long" });
  const list = (items, type = "conjunction") => format.list(items, { type });

  const d = describeCron(cron);
  let sentence = null;
  if (d.time) {
    const timeText = {
      everyMinute: () => t("everyMinute"),
      everyMinutes: () => t("everyMinutes", { n: d.time.n }),
      // Example times rather than idioms like "on the hour".
      hourly: () => t("hourly", { examples: [0, 1, 2].map((h) => time([h, d.time.minute])).join(", ") }),
      everyHours: () =>
        t("everyHours", { n: d.time.n, examples: [0, 1, 2].map((i) => time([i * d.time.n, d.time.minute])).join(", ") }),
      everyBetween: () => t("everyBetween", { n: d.time.n, from: time(d.time.from), to: time(d.time.to) }),
      at: () => t("at", { times: list(d.time.times.map(time)) }),
    }[d.time.kind]();

    const weekdaysText = d.weekdays
      ? d.weekdayRange
        ? t("onWeekdayRange", { from: weekday(d.weekdayRange[0]), to: weekday(d.weekdayRange[1]) })
        : t("onWeekdays", { weekdays: list(d.weekdays.map(weekday)) })
      : null;
    const daysText = d.days ? t("onDays", { count: d.days.length, days: list(d.days.map(String)) }) : null;
    let when = daysText && weekdaysText ? t("daysOrWeekdays", { days: daysText, weekdays: weekdaysText }) : (daysText ?? weekdaysText);
    if (d.months) when = t("inMonths", { days: when ?? t("everyDay"), months: list(d.months.map(month)) });

    sentence =
      d.time.kind === "at"
        ? t("sentenceAt", { days: when ?? t("everyDay"), time: timeText })
        : when
          ? t("sentenceRepeatOn", { time: timeText, days: when })
          : t("sentenceRepeat", { time: timeText });
  }

  const runs = nextRuns(cron, wallClockNow(timezone, now), 3).map((run) =>
    format.dateTime(run, { ...UTC, weekday: "short", day: "numeric", month: "short", hour: "numeric", minute: "2-digit" }),
  );

  return { sentence, runs, never: runs.length === 0 };
}

export function SchedulePreview({ expression, timezone }) {
  const t = useTranslations("cronJobs.preview");
  const text = useScheduleText(expression, timezone);
  if (!text) return null;

  if (text.never) {
    return (
      <p className="flex items-start gap-1.5 text-xs text-warning">
        <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
        {t("never")}
      </p>
    );
  }

  return (
    <div className="flex items-start gap-2 rounded-md border bg-muted/40 px-3 py-2 text-sm" aria-live="polite">
      <CalendarClock className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
      <div className="min-w-0 space-y-0.5">
        {text.sentence ? <p className="font-medium">{text.sentence}</p> : null}
        {/* Each date kept whole, so a wrap falls between dates, never inside one. */}
        <p className="text-xs text-muted-foreground">
          {t("nextRuns", { runs: text.runs.map((run) => run.replace(/ /g, "\u00a0")).join(" · ") })}
        </p>
      </div>
    </div>
  );
}
