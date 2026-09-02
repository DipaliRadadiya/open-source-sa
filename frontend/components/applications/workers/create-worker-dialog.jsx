import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Cog, ChevronDown } from "lucide-react";
import { workerFormSchema, WORKER_FORM_DEFAULTS } from "@/lib/schemas/worker";
import { createWorker } from "@/lib/api/workers";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
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
    const payload = {
      ...values,
      name: values.name.trim(),
      command: values.command.trim(),
      directory: values.directory?.trim() || undefined,
    };

    try {
      await createWorker(appId, payload);
      toast.success(t("toast.created"));
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  function handleOpenChange(next) {
    if (!next) form.reset(WORKER_FORM_DEFAULTS);
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
            <FormField
              control={form.control}
              name="directory"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t("form.directory")}</FormLabel>
                  <FormControl>
                    <Input
                      className="font-mono"
                      autoComplete="off"
                      spellCheck={false}
                      placeholder={t("form.directoryPlaceholder")}
                      {...field}
                    />
                  </FormControl>
                  <p className="text-xs text-muted-foreground">{t("form.directoryHint")}</p>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="stop_wait_seconds"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("form.stopWaitSeconds")}</FormLabel>
                  <FormControl>
                    <Input placeholder="10" type="number" inputMode="numeric" min={1} max={300} {...field} />
                  </FormControl>
                  <p className="text-xs text-muted-foreground">{t("form.stopWaitSecondsHint")}</p>
                  <FormMessage />
                </FormItem>
              )}
            />
          </CollapsibleContent>
        </Collapsible>
      </FormModal>
    </Form>
  );
}
