import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, CalendarCog } from "lucide-react";
import { updateCronjobSchema, OTHER_USER } from "@/lib/schemas/cronjob";
import { updateCronjob } from "@/lib/api/cronjobs";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
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

// The form's view of who a job runs as: a panel account by id, or "other"
// plus the raw username (root, www-data, anything the panel doesn't manage).
function valuesOf(job) {
  return {
    name: job.name,
    run_as: job.system_user ? String(job.system_user.id) : OTHER_USER,
    username: job.system_user ? "" : job.username,
    command: job.command,
    expression: job.expression,
    active: job.active,
  };
}

export function EditCronjobDialog({
  job,
  open,
  onOpenChange,
  systemUsers = [],
  systemUsersFailed = false,
  schedulePresets = [],
  commandPresets = [],
  applications = [],
  placeholder,
  // Shown under the schedule so the clock it runs on is stated, as in Create.
  timezone,
}) {
  const t = useTranslations("cronJobs");
  const tc = useTranslations("common");
  const { refresh, refreshThen } = useRefresh();

  const form = useForm({
    resolver: zodResolver(updateCronjobSchema),
    defaultValues: valuesOf(job),
  });

  // The row's props change under us after router.refresh(); re-seed so the form
  // doesn't reopen holding pre-edit values.
  useEffect(() => {
    if (open) form.reset(valuesOf(job));
    // Depends on the job's own fields, so a reopened form holds what was saved.
    // `next_run_at` is excluded so the due-time re-read does not reset an edit.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, job.id, job.name, job.command, job.expression, job.active, job.username, job.system_user?.id]);

  async function onSubmit(values) {
    const before = valuesOf(job);
    // Sent only when changed: an unchanged account needs no re-check on disk.
    const runAsChanged =
      values.run_as !== before.run_as ||
      (values.run_as === OTHER_USER && values.username.trim() !== before.username);
    try {
      await updateCronjob(job.id, {
        name: values.name.trim(),
        command: values.command.trim(),
        expression: values.expression.trim(),
        active: values.active,
        ...(runAsChanged
          ? values.run_as === OTHER_USER
            ? { system_user_id: null, username: values.username.trim() }
            : { system_user_id: Number(values.run_as) }
          : {}),
      });
      // "Saving…" stays until the row shows the change.
      await new Promise((resolve) => refreshThen(resolve));
      toast.success(t("toast.updated"));
      onOpenChange?.(false);
    } catch (error) {
      // Removed from another tab: nothing left to edit, and the row must go.
      if (error?.response?.status === 404) {
        toast.info(t("toast.alreadyGone", { name: job.name }));
        onOpenChange?.(false);
        refresh();
        return;
      }
      // Field errors go on the fields; anything else says what failed.
      if (error?.response?.data?.errors) handleValidationError(error, form);
      else toast.error(apiMessage(error, t("toast.updateFailed")));
    }
  }

  const isSubmitting = form.formState.isSubmitting;
  // Nothing changed means nothing to save.
  const isDirty = form.formState.isDirty;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={onOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={CalendarCog}
        title={t("edit.title")}
        description={t("edit.subtitle")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => onOpenChange?.(false)}
            >
              {t("cancel")}
            </Button>
            <ReasonTooltip reason={!isDirty && !isSubmitting ? tc("nothingToSave") : null}>
              <Button type="submit" disabled={isSubmitting || !isDirty}>
                {isSubmitting && <Loader2 className="size-4 animate-spin" />}
                {isSubmitting ? t("saving") : t("edit.submit")}
              </Button>
            </ReasonTooltip>
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
                <Input autoComplete="off" placeholder={t("form.namePlaceholder")} {...field} />
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
