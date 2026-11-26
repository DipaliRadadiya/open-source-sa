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
import { FormModal } from "@/components/ui/form-modal";
import { Form } from "@/components/ui/form";
import { CronjobFormFields } from "@/components/cron-jobs/cronjob-form-fields";

const DEFAULTS = {
  name: "",
  run_as: "",
  username: "",
  command: "",
  expression: "",
  active: true,
  application_id: null,
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
  // Re-seeded on open so a second open with another source can't show old values.
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
    // The API takes system_user_id or username, never both.
    const payload = {
      name: values.name.trim(),
      command: values.command.trim(),
      expression: values.expression.trim(),
      active: values.active,
      ...(values.application_id ? { application_id: values.application_id } : {}),
      ...(values.run_as === OTHER_USER
        ? { username: values.username.trim() }
        : { system_user_id: Number(values.run_as) }),
    };

    try {
      await createCronjob(payload);
      await new Promise((resolve) => refreshThen(resolve));
      toast.success(t("toast.created"));
      onOpenChange?.(false);
      form.reset(DEFAULTS);
    } catch (error) {
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
        <CronjobFormFields
          form={form}
          systemUsers={systemUsers}
          systemUsersFailed={systemUsersFailed}
          schedulePresets={schedulePresets}
          commandPresets={commandPresets}
          applications={applications}
          placeholder={placeholder}
          timezone={timezone}
          starterKey={starterKey}
        />
      </FormModal>
    </Form>
  );
}
