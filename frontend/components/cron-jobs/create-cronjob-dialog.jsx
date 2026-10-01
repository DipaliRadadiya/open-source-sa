import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, CalendarPlus } from "lucide-react";
import { createCronjobSchema, OTHER_USER } from "@/lib/schemas/cronjob";
import { createCronjob } from "@/lib/api/cronjobs";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";
import { ScheduleField } from "@/components/cron-jobs/schedule-field";
import { CommandField } from "@/components/cron-jobs/command-field";
import { RunAsField } from "@/components/cron-jobs/run-as-field";

const DEFAULTS = {
  name: "",
  run_as: "",
  username: "",
  command: "",
  expression: "",
  active: true,
};

export function CreateCronjobDialog({
  open,
  onOpenChange,
  systemUsers = [],
  systemUsersFailed = false,
  schedulePresets = [],
  commandPresets = [],
  applications = [],
  placeholder,
  timezone,
  // Prefill from a duplicated job or a quick-start template. Re-seeded on open
  // so opening the dialog twice with different sources can't show stale values.
  initialValues,
  starterKey,
}) {
  const t = useTranslations("cronJobs");
  const { refreshThen } = useRefresh();

  const form = useForm({
    resolver: zodResolver(createCronjobSchema),
    defaultValues: { ...DEFAULTS, ...initialValues },
  });

  useEffect(() => {
    if (open) form.reset({ ...DEFAULTS, ...initialValues });
    // `initialValues` is held in state by the parent (cronjobs-panel.jsx), so
    // its identity changes only when a different quick-start is chosen.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, initialValues]);


  async function onSubmit(values) {
    // The API takes system_user_id XOR username — never both.
    const payload = {
      name: values.name.trim(),
      command: values.command.trim(),
      expression: values.expression.trim(),
      active: values.active,
      ...(values.run_as === OTHER_USER
        ? { username: values.username.trim() }
        : { system_user_id: Number(values.run_as) }),
    };

    try {
      await createCronjob(payload);
      // The dialog keeps "Saving…" until the new row is on screen.
      await new Promise((resolve) => refreshThen(resolve));
      toast.success(t("toast.created"));
      onOpenChange?.(false);
      form.reset(DEFAULTS);
    } catch (error) {
      // Field errors go on the fields; anything else says what failed.
      if (error?.response?.data?.errors) handleValidationError(error, form);
      else toast.error(apiMessage(error, t("toast.createFailed")));
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  function handleOpenChange(next) {
    if (!next) form.reset(DEFAULTS);
    onOpenChange?.(next);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={CalendarPlus}
        title={t("create.title")}
        description={t("create.subtitle")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => handleOpenChange(false)}
            >
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("saving") : t("create.submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("form.name")}</FormLabel>
              <FormControl>
                <Input
                  placeholder={t("form.namePlaceholder")}
                  autoComplete="off"
                  {...field}
                />
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
                {/* `active` is always sent, so a 422 on it needs a place to render. */}
                <FormMessage />
              </div>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}
