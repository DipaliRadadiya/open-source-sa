import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Cog, ChevronDown } from "lucide-react";
import { Caution } from "@/components/ui/caution";
import { workerFormSchema, WORKER_FORM_DEFAULTS } from "@/lib/schemas/worker";
import { createWorker } from "@/lib/api/workers";
import { handleValidationError } from "@/lib/api/handle-validation-error";
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

/**
 * Presets prefill both name and command, but stay a starting point, not a
 * locked template — the two most-common cases (Queue worker, Horizon) are one
 * click away, and everything after that is a normal editable form. Advanced
 * fields (directory, stop-wait) sit behind a disclosure so the common path is
 * four visible fields: name, command, processes, and the two safety switches.
 */
export function CreateWorkerDialog({ open, onOpenChange, appId, presets = [], workers = [], seed }) {
  const t = useTranslations("applications.workers");
  const router = useRouter();
  // The server's own "installing supervisor" message, kept on screen until
  // the next attempt. Null when there is nothing to say.
  const [installing, setInstalling] = useState(null);

  const form = useForm({
    resolver: zodResolver(workerFormSchema),
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
    // `presets` and `form` are excluded — `form` is stable, and `presets` is the
    // catalog the seed is looked up in rather than something that should
    // re-seed the form when its array identity changes.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, seed]);

  function onPickPreset(preset) {
    form.setValue("kind", preset.kind, { shouldValidate: true });
    // Custom command ships with an empty command on purpose (type your own) —
    // validating it immediately would flag "required" before anyone's typed.
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
    // Cleared on every attempt: the notice below describes the LAST answer,
    // and leaving a stale "installing" above a fresh validation error reads as
    // two contradictory explanations for one press.
    setInstalling(null);

    const payload = {
      ...values,
      name: values.name.trim(),
      command: values.command.trim(),
      directory: values.directory?.trim() || undefined,
      // Blank means "no opinion", and the API treats an absent key that way —
      // sending "" would ask it to store an empty username and an empty log
      // path, which is not the same request at all.
      user: values.user?.trim() || undefined,
      log_file: values.log_file?.trim() || undefined,
      log_level: values.log_level || undefined,
      extra_config: values.extra_config?.trim() || undefined,
      auto_start: values.auto_start,
    };

    try {
      const response = await createWorker(appId, payload);

      // 202, not 201: the server had no supervisord and has started installing
      // it. apt is minutes long and cannot be held inside a request, so no
      // worker exists yet — saying "Created" here would name a thing that is
      // not there. The dialog stays open with the form intact, so the same
      // worker is one more click once the install lands.
      if (response?.status === 202) {
        // A toast AND a notice, not a toast alone. The toast fades after a few
        // seconds and leaves a filled-in form that looks like the button did
        // nothing — the one reading of this screen that is flatly wrong, since
        // an apt install is running because of that press.
        toast.info(response.data?.message ?? t("toast.installingSupervisor"));
        setInstalling(response.data?.message ?? t("toast.installingSupervisor"));

        return;
      }

      toast.success(t("toast.created"));
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

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
        {/* Above the fields, because it explains why they are still filled in
            and still here. */}
        {installing ? <Caution>{installing}</Caution> : null}

        <div className="grid grid-cols-2 gap-4">
          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <FormItem>
                <FormLabel required>{t("form.name")}</FormLabel>
                <FormControl>
                  <Input placeholder={t("form.namePlaceholder")} autoComplete="off" {...field} />
                </FormControl>
                <p className="text-xs text-muted-foreground">{t("form.nameHint")}</p>
                <FormMessage />
              </FormItem>
            )}
          />

          <FormField
            control={form.control}
            name="processes"
            render={({ field }) => (
              <FormItem>
                <FormLabel required>{t("form.processes")}</FormLabel>
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
                <p className="text-xs text-muted-foreground">{t("form.processesHint")}</p>
                <FormMessage />
              </FormItem>
            )}
          />
        </div>

        <WorkerCommandField form={form} presets={presets} workers={workers} onPick={onPickPreset} />

        <FormField
          control={form.control}
          name="restart_on_deploy"
          render={({ field }) => (
            <FormItem className="flex items-center justify-between rounded-lg border px-3 py-2.5">
              <div className="space-y-0.5">
                <FormLabel>{t("form.restartOnDeploy")}</FormLabel>
                <p className="text-xs text-muted-foreground">{t("form.restartOnDeployHint")}</p>
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
                <FormLabel>{t("form.autoRestart")}</FormLabel>
                <p className="text-xs text-muted-foreground">{t("form.autoRestartHint")}</p>
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
                <FormLabel>{t("form.enabled")}</FormLabel>
                <p className="text-xs text-muted-foreground">{t("form.enabledHint")}</p>
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
            <WorkerAdvancedFields form={form} />
          </CollapsibleContent>
        </Collapsible>
      </FormModal>
    </Form>
  );
}
