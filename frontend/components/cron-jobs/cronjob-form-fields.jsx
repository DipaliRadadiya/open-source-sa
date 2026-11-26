import { useTranslations } from "next-intl";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { FormField, FormItem, FormLabel, FormControl, FormMessage } from "@/components/ui/form";
import { ScheduleField } from "@/components/cron-jobs/schedule-field";
import { CommandField } from "@/components/cron-jobs/command-field";
import { RunAsField } from "@/components/cron-jobs/run-as-field";

// The fields of the Add and Edit cron job dialogs; each dialog keeps its own save.
export function CronjobFormFields({
  form,
  systemUsers,
  systemUsersFailed,
  schedulePresets,
  commandPresets,
  applications,
  placeholder,
  timezone,
  starterKey,
}) {
  const t = useTranslations("cronJobs");

  return (
    <>
      <FormField
        control={form.control}
        name="name"
        render={({ field }) => (
          <FormItem>
            <FormLabel required>{t("form.name")}</FormLabel>
            <FormControl>
              <Input placeholder={t("form.namePlaceholder")} autoComplete="off" {...field} />
            </FormControl>
            <FormMessage />
          </FormItem>
        )}
      />

      <RunAsField form={form} systemUsers={systemUsers} systemUsersFailed={systemUsersFailed} />

      <CommandField
        form={form}
        presets={commandPresets}
        placeholder={placeholder}
        starterKey={starterKey}
        applications={applications}
      />
      <ScheduleField form={form} presets={schedulePresets} timezone={timezone} />

      <FormField
        control={form.control}
        name="active"
        render={({ field }) => (
          <FormItem className="flex items-center justify-between rounded-lg border px-3 py-2.5">
            <div className="space-y-0.5">
              <FormLabel hint={t("form.activeHint")}>{t("form.active")}</FormLabel>
              <FormMessage />
            </div>
            <FormControl>
              <Switch checked={field.value} onCheckedChange={field.onChange} />
            </FormControl>
          </FormItem>
        )}
      />
    </>
  );
}
