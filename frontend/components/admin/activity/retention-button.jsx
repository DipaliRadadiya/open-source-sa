"use client";

import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { History, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { useRefresh } from "@/hooks/use-refresh";
import { updateActivityRetention } from "@/lib/api/activity-log";
import { apiMessage } from "@/lib/api/error-message";
import { parseApiWallClock } from "@/lib/format/api-date";
import { activityRetentionSchema } from "@/lib/schemas/activity";
import { retentionCutoff } from "@/lib/activity-log/retention";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

// Radix Select cannot hold null, which is how the API says "keep everything".
const NEVER = "never";
const RECOMMENDED = 12;
const toValue = (months) => (months == null ? NEVER : String(months));

// How long the activity log is kept, for every user, so admin only (the page is). A header
// button showing the setting, not a card above the log: it is changed once, read daily
//.
export function RetentionButton({ retention }) {
  const t = useTranslations("activity.retention");
  const tc = useTranslations("common");
  const format = useFormatter();
  const { refreshAndWait } = useRefresh();
  const [saved, setSaved] = useState(retention);
  const [open, setOpen] = useState(false);
  const [value, setValue] = useState(toValue(retention.months));
  const [pending, setPending] = useState(false);
  // Read when the window opens, so the preview's date stays put while it is open.
  const [now, setNow] = useState(null);

  const savedValue = toValue(saved.months);
  const oldest = parseApiWallClock(saved.oldest_at);
  const date = (d) => format.dateTime(d, { dateStyle: "medium", timeZone: "UTC" });
  const monthsLabel = (months) => t("months", { count: months });

  function openWindow() {
    setValue(savedValue);
    setNow(Date.now());
    setOpen(true);
  }

  async function save(event) {
    event.preventDefault();
    if (value === savedValue) {
      setOpen(false);
      return;
    }
    setPending(true);
    try {
      const { data } = await updateActivityRetention(value === NEVER ? null : Number(value));
      const parsed = activityRetentionSchema.safeParse(data);
      setSaved(parsed.success ? parsed.data.retention : { ...saved, months: value === NEVER ? null : Number(value) });
      await refreshAndWait();
      setOpen(false);
      toast.success(t("saved"));
    } catch (error) {
      toast.error(apiMessage(error, t("saveFailed")));
    } finally {
      setPending(false);
    }
  }

  // What the choice would do, before it is saved.
  const cutoff = value === NEVER || now === null ? null : retentionCutoff(now, Number(value));
  const preview =
    value === NEVER
      ? { text: t("previewNever") }
      : oldest && cutoff && oldest < cutoff
        ? { text: t("previewDelete", { date: date(cutoff) }), warn: true }
        : oldest
          ? { text: t("previewNone", { date: date(oldest) }) }
          : { text: t("previewEmpty") };

  const inLog =
    saved.entries == null
      ? null
      : oldest
        ? t("entriesSince", { count: saved.entries, entries: format.number(saved.entries), date: date(oldest) })
        : t("entries", { count: saved.entries, entries: format.number(saved.entries) });

  return (
    <>
      <Button variant="outline" onClick={openWindow}>
        <History className="size-4" />
        {saved.months == null ? t("keptForever") : t("kept", { period: monthsLabel(saved.months) })}
      </Button>

      <FormModal
        open={open}
        onOpenChange={(next) => !pending && setOpen(next)}
        asForm
        onSubmit={save}
        icon={History}
        title={t("title")}
        description={t("description")}
        footer={
          <>
            <Button type="button" variant="outline" onClick={() => setOpen(false)} disabled={pending}>
              {tc("cancel")}
            </Button>
            <Button type="submit" disabled={pending}>
              {pending ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("save")}
            </Button>
          </>
        }
      >
        <div className="space-y-2">
          {/* A plain label, not FormLabel: this window has no react-hook-form. */}
          <label htmlFor="activity-retention" className="text-sm font-medium">
            {t("label")}
          </label>
          <Select value={value} onValueChange={setValue} disabled={pending}>
            <SelectTrigger id="activity-retention" className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent position="popper">
              <SelectItem value={NEVER}>{t("forever")}</SelectItem>
              {saved.choices.map((months) => (
                <SelectItem key={months} value={String(months)}>
                  {months === RECOMMENDED ? t("recommended", { label: monthsLabel(months) }) : monthsLabel(months)}
                </SelectItem>
              ))}
            </SelectContent>
          </Select>
          <p aria-live="polite" className={cn("text-sm", preview.warn ? "font-medium text-warning" : "text-muted-foreground")}>
            {preview.text}
          </p>
        </div>

        <div className="space-y-1 rounded-lg border bg-muted/30 px-3 py-2.5 text-xs text-muted-foreground">
          {inLog ? <p>{t("inLog", { summary: inLog })}</p> : null}
          <p>{t("hint")}</p>
        </div>
      </FormModal>
    </>
  );
}
