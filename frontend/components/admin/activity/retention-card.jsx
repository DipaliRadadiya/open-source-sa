"use client";

import { useState } from "react";
import { useFormatter, useTranslations } from "next-intl";
import { toast } from "sonner";
import { History } from "lucide-react";
import { useRefresh } from "@/hooks/use-refresh";
import { updateActivityRetention } from "@/lib/api/activity-log";
import { apiMessage } from "@/lib/api/error-message";
import { parseApiWallClock } from "@/lib/format/api-date";
import { activityRetentionSchema } from "@/lib/schemas/activity";
import { InfoRow, Section, SectionActions } from "@/components/settings/setting-row";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";

// Radix Select cannot hold null, which is how the API says "keep everything".
const NEVER = "never";
const RECOMMENDED = 12;

// How long the activity log is kept, for every user, so admin only (the page is).
export function RetentionCard({ retention }) {
  const t = useTranslations("activity.retention");
  const format = useFormatter();
  const { refreshAndWait } = useRefresh();
  const [saved, setSaved] = useState(retention);
  const [value, setValue] = useState(retention.months == null ? NEVER : String(retention.months));
  const [pending, setPending] = useState(false);

  const savedValue = saved.months == null ? NEVER : String(saved.months);
  const dirty = value !== savedValue;
  const oldest = parseApiWallClock(saved.oldest_at);

  async function save(event) {
    event.preventDefault();
    if (!dirty) return;
    setPending(true);
    try {
      const { data } = await updateActivityRetention(value === NEVER ? null : Number(value));
      const parsed = activityRetentionSchema.safeParse(data);
      const next = parsed.success ? parsed.data.retention : { ...saved, months: value === NEVER ? null : Number(value) };
      setSaved(next);
      setValue(next.months == null ? NEVER : String(next.months));
      await refreshAndWait();
      toast.success(t("saved"));
    } catch (error) {
      toast.error(apiMessage(error, t("saveFailed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <form noValidate onSubmit={save}>
      <Section
        icon={History}
        title={t("title")}
        description={t("description")}
        actions={
          <SectionActions
            label={t("save")}
            isDirty={dirty}
            pending={pending}
            onDiscard={() => setValue(savedValue)}
            canManage
          />
        }
      >
        {/* A plain label, not FormLabel: this card has no react-hook-form. */}
        <div className="space-y-2">
          <label htmlFor="activity-retention" className="text-sm font-medium">
            {t("label")}
          </label>
          <Select value={value} onValueChange={setValue} disabled={pending}>
            <SelectTrigger id="activity-retention" className="w-full">
              <SelectValue />
            </SelectTrigger>
            <SelectContent position="popper">
              {saved.choices.map((months) => (
                <SelectItem key={months} value={String(months)}>
                  {months === RECOMMENDED ? t("recommended", { label: t("months", { count: months }) }) : t("months", { count: months })}
                </SelectItem>
              ))}
              <SelectItem value={NEVER}>{t("never")}</SelectItem>
            </SelectContent>
          </Select>
          {value !== NEVER ? <p className="text-xs leading-relaxed text-muted-foreground">{t("hint")}</p> : null}
        </div>

        <InfoRow label={t("now")}>
          <p className="text-sm">
            {saved.entries == null
              ? t("entriesUnknown")
              : oldest
                ? t("entriesSince", {
                    count: saved.entries,
                    entries: format.number(saved.entries),
                    date: format.dateTime(oldest, { dateStyle: "medium", timeZone: "UTC" }),
                  })
                : t("entries", { count: saved.entries, entries: format.number(saved.entries) })}
          </p>
        </InfoRow>
      </Section>
    </form>
  );
}
