import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Cog, ChevronDown, TriangleAlert } from "lucide-react";
import { Caution } from "@/components/ui/caution";
import { workerFormSchemaFor, WORKER_FORM_DEFAULTS } from "@/lib/schemas/worker";
import { createWorker } from "@/lib/api/workers";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { apiMessage } from "@/lib/api/error-message";
import { useRefresh } from "@/hooks/use-refresh";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { WorkerAdvancedFields } from "@/components/applications/workers/worker-advanced-fields";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { FormModal } from "@/components/ui/form-modal";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";
import { WorkerCommandField } from "@/components/applications/workers/worker-command-field";
import { WorkerKindField } from "@/components/applications/workers/worker-kind-field";
import { useWorkerSite } from "@/components/applications/workers/worker-site-context";

// Presets prefill name and command as an editable starting point.
export function CreateWorkerDialog({ open, onOpenChange, appId, presets = [], workers = [], seed, siteUser = null }) {
  const t = useTranslations("applications.workers");
  const { pending: refreshing, refreshThen } = useRefresh();
  // The server's "installing supervisor" message, kept until the next attempt.
  const [installing, setInstalling] = useState(null);

  const { appRoot } = useWorkerSite();
  const form = useForm({
    resolver: zodResolver(workerFormSchemaFor(appRoot)),
    defaultValues: WORKER_FORM_DEFAULTS,
  });

  useEffect(() => {
    if (!open) return;
    const preset = seed ? presets.find((p) => p.key === seed) : null;
    form.reset({
      ...WORKER_FORM_DEFAULTS,
      ...(preset
        ? { name: preset.title, command: preset.command, kind: preset.kind }
        : null),
    });
    // `form` is stable; a new `presets` array identity must not re-seed the form.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, seed]);

  function onPickPreset(preset) {
    form.setValue("kind", preset.kind, { shouldValidate: true });
    // The custom preset has an empty command; do not flag "required" before typing.
    if (preset.command) {
      form.setValue("command", preset.command, { shouldValidate: true });
    } else {
      form.setValue("command", preset.command);
      form.clearErrors("command");
    }
    if (!form.getValues("name")) {
      form.setValue("name", preset.title, { shouldValidate: true });
    }
  }

  async function onSubmit(values) {
    // Both describe the last attempt only; a root error is cleared by nothing else.
    setInstalling(null);
    form.clearErrors("root.server");

    const payload = {
      ...values,
      name: values.name.trim(),
      command: values.command.trim(),
      directory: values.directory?.trim() || undefined,
      // Never sent: the worker runs as the application's own user.
      user: undefined,
      // Blank is omitted; "" would store an empty log path.
      log_file: values.log_file?.trim() || undefined,
      log_level: values.log_level || undefined,
      extra_config: values.extra_config?.trim() || undefined,
      auto_start: values.auto_start,
    };

    try {
      const response = await createWorker(appId, payload);

      // 202: supervisord is being installed and no worker exists yet. The dialog
      // stays open with the form intact so it can be resubmitted afterwards.
      if (response?.status === 202) {
        // Toast plus a persistent notice, since the toast fades.
        toast.info(response.data?.message ?? t("toast.installingSupervisor"));
        setInstalling(response.data?.message ?? t("toast.installingSupervisor"));

        return;
      }

      // Closed once the list shows the change, not on the API's answer.
      refreshThen(() => {
        toast.success(t("toast.created"));
        onOpenChange?.(false);
      });
    } catch (error) {
      // A command that will not start answers 500 without field errors (the
      // server has removed the worker); show it on the form.
      if (!error.response?.data?.errors && (error.response?.status ?? 0) >= 500) {
        form.setError("root.server", { message: apiMessage(error, t("create.failed")) });
        scrollToFirstError();
        return;
      }
      // `kind` errors (e.g. a Horizon conflict) are shown at form level.
      handleValidationError(error, form, { formError: true, unrendered: ["kind"] });
      // The dialog scrolls; the reason can land above or below what is on screen.
      scrollToFirstError();
    }
  }

  // Busy through the list refresh so the button cannot be pressed twice.
  const isSubmitting = form.formState.isSubmitting || refreshing;
  const serverError = form.formState.errors.root?.server?.message;

  function handleOpenChange(next) {
    if (!next) {
      form.reset(WORKER_FORM_DEFAULTS);
      setInstalling(null);
    }
    onOpenChange?.(next);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={Cog}
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
        {installing ? <Caution>{installing}</Caution> : null}

        {serverError ? (
          <p
            role="alert"
            data-form-error
            className="flex items-start gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2.5 text-sm leading-relaxed text-destructive"
          >
            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
            {serverError}
          </p>
        ) : null}

        <div className="grid grid-cols-2 gap-4">
          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <FormItem>
                <FormLabel required hint={t("form.nameHint")}>{t("form.name")}</FormLabel>
                <FormControl>
                  <Input placeholder={t("form.namePlaceholder")} autoComplete="off" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />

          <FormField
            control={form.control}
            name="processes"
            render={({ field }) => (
              <FormItem>
                <FormLabel required hint={t("form.processesHint")}>{t("form.processes")}</FormLabel>
                <FormControl>
                  <Input
                    placeholder="1"
                    type="number"
                    inputMode="numeric"
                    min={1}
                    max={16}
                    {...field}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </div>

        {/* Unfiltered: every existing worker counts against the choice. */}
        <WorkerKindField form={form} presets={presets} workers={workers} />

        <WorkerCommandField form={form} presets={presets} workers={workers} onPick={onPickPreset} />

        <FormField
          control={form.control}
          name="restart_on_deploy"
          render={({ field }) => (
            <FormItem className="flex items-center justify-between rounded-lg border px-3 py-2.5">
              <div className="space-y-0.5">
                <FormLabel hint={t("form.restartOnDeployHint")}>{t("form.restartOnDeploy")}</FormLabel>
              </div>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="auto_restart"
          render={({ field }) => (
            <FormItem className="flex items-center justify-between rounded-lg border px-3 py-2.5">
              <div className="space-y-0.5">
                <FormLabel hint={t("form.autoRestartHint")}>{t("form.autoRestart")}</FormLabel>
              </div>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="enabled"
          render={({ field }) => (
            <FormItem className="flex items-center justify-between rounded-lg border px-3 py-2.5">
              <div className="space-y-0.5">
                <FormLabel hint={t("form.enabledHint")}>{t("form.enabled")}</FormLabel>
              </div>
              <FormControl>
                <Switch checked={field.value} onCheckedChange={field.onChange} />
              </FormControl>
            </FormItem>
          )}
        />

        {/* Keyed on `open` so it remounts collapsed every time the dialog
            opens, instead of tracking its own reset-on-open state. */}
        <Collapsible key={String(open)}>
          <CollapsibleTrigger asChild>
            <Button
              type="button"
              variant="ghost"
              size="sm"
              className="group -ml-2 gap-1 text-xs font-medium text-muted-foreground"
            >
              <ChevronDown className="size-3.5 transition-transform group-data-[state=open]:rotate-180" />
              {t("form.advanced")}
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="space-y-4 pt-3">
            <WorkerAdvancedFields form={form} runsAs={siteUser} />
          </CollapsibleContent>
        </Collapsible>
      </FormModal>
    </Form>
  );
}
