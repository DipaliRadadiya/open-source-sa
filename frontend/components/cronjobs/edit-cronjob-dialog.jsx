"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, CalendarCog } from "lucide-react";
import { updateCronjobSchema } from "@/lib/schemas/cronjob";
import { updateCronjob } from "@/lib/api/cronjobs";
import { handleValidationError } from "@/lib/api/handle-validation-error";
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
import { ScheduleField } from "@/components/cronjobs/schedule-field";
import { CommandField } from "@/components/cronjobs/command-field";

export function EditCronjobDialog({
  job,
  open,
  onOpenChange,
  schedulePresets = [],
  commandPresets = [],
  placeholder,
}) {
  const t = useTranslations("cronJobs");
  const router = useRouter();

  const form = useForm({
    resolver: zodResolver(updateCronjobSchema),
    defaultValues: {
      name: job.name,
      command: job.command,
      expression: job.expression,
      active: job.active,
    },
  });

  // The row's props change under us after router.refresh(); re-seed so the form
  // doesn't reopen holding pre-edit values.
  useEffect(() => {
    if (open) {
      form.reset({
        name: job.name,
        command: job.command,
        expression: job.expression,
        active: job.active,
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, job.id, job.name, job.command, job.expression, job.active]);

  async function onSubmit(values) {
    try {
      await updateCronjob(job.id, {
        name: values.name.trim(),
        command: values.command.trim(),
        expression: values.expression.trim(),
        active: values.active,
      });
      toast.success(t("toast.updated"));
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;
  // Nothing changed means nothing to save — a live button that fires a
  // no-op write reads as if it did something.
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
            <Button type="submit" disabled={isSubmitting || !isDirty}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("saving") : t("edit.submit")}
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
                <Input autoComplete="off" placeholder={t("form.namePlaceholder")} {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        {/* Run-as is fixed server-side (change = delete + recreate), so it's
            shown as context rather than silently omitted. */}
        <FormItem>
          <FormLabel>{t("form.runAs")}</FormLabel>
          <Input value={job.username} readOnly disabled className="font-mono" />
          <p className="text-xs text-muted-foreground">{t("form.runAsLocked")}</p>
        </FormItem>

        <CommandField
          form={form}
          presets={commandPresets}
          placeholder={placeholder}
        />
        <ScheduleField form={form} presets={schedulePresets} />

        <FormField
          control={form.control}
          name="active"
          render={({ field }) => (
            <FormItem className="flex items-center justify-between rounded-lg border px-3 py-2.5">
              <div className="space-y-0.5">
                <FormLabel>{t("form.active")}</FormLabel>
                <p className="text-xs text-muted-foreground">{t("form.activeHint")}</p>
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
