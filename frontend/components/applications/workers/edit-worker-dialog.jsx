import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Pencil, ChevronDown, TriangleAlert } from "lucide-react";
import { workerFormSchema } from "@/lib/schemas/worker";
import { updateWorker } from "@/lib/api/workers";
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
import { WorkerKindField } from "@/components/applications/workers/worker-kind-field";

function valuesFrom(worker) {
  return {
    name: worker.name,
    command: worker.command,
    kind: worker.kind,
    processes: worker.processes,
    directory: worker.directory ?? "",
    stop_wait_seconds: worker.stop_wait_seconds ?? 30,
    // `user`, not `effective_user`: the form edits what was ASKED for, and
    // showing the resolved fallback here would save the site's own username as
    // if it had been chosen deliberately.
    user: worker.user ?? "",
    log_file: worker.log_file ?? "",
    log_level: worker.log_level ?? "",
    extra_config: worker.extra_config ?? "",
    auto_start: worker.auto_start ?? true,
    auto_restart: worker.auto_restart,
    restart_on_deploy: worker.restart_on_deploy,
    enabled: worker.enabled,
  };
}

export function EditWorkerDialog({ worker, appId, presets = [], workers = [], open, onOpenChange }) {
  const t = useTranslations("applications.workers");
  const router = useRouter();

  const form = useForm({
    resolver: zodResolver(workerFormSchema),
    defaultValues: valuesFrom(worker),
  });

  // Keyed on the id, like every other edit dialog in the panel — NOT on the
  // `worker` object. The list this row comes from is polled, so that object is
  // replaced on every tick, and depending on it re-ran this reset every few
  // seconds: it silently threw away whatever was being typed, and because
  // reset() also clears `isSubmitting` it killed the Save spinner mid-request
  // and re-enabled the button while the write was still in the air.
  useEffect(() => {
    if (open) form.reset(valuesFrom(worker));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, worker.id]);

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
  }

  async function onSubmit(values) {
    // Last attempt's refusal, cleared before this one. It belongs to no field,
    // so nothing else clears it, and a stale conflict sitting above a worker
    // you have since changed is worse than no message.
    form.clearErrors("root.server");
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
      await updateWorker(appId, worker.id, payload);
      toast.success(t("toast.updated"));
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      /*
       * `kind` is sent and is in the form's values, but has no control — it is
       * set by picking a preset. So the API's "you can't run Horizon and a
       * queue worker on the same app" was stored against an input that does not
       * exist, and Save failed in total silence. Naming it here puts the
       * refusal on the form, where it stays put while the dialog does.
       */
      handleValidationError(error, form, { formError: true, unrendered: ["kind"] });
    }
  }

  const isSubmitting = form.formState.isSubmitting;
  const serverError = form.formState.errors.root?.server?.message;
  const others = workers.filter((w) => w.id !== worker.id);

  function handleOpenChange(next) {
    if (!next) form.reset(valuesFrom(worker));
    onOpenChange?.(next);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={Pencil}
        title={t("edit.title")}
        description={t("edit.subtitle")}
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
              {isSubmitting ? t("saving") : t("edit.submit")}
            </Button>
          </>
        }
      >
        {serverError ? (
          <p
            role="alert"
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

        {/* Both controls set `kind`, and both must exclude this worker from the
            conflict check — changing a site's only queue worker into a Horizon
            one is the edit that is always safe. */}
        <WorkerKindField form={form} workers={others} />

        <WorkerCommandField
          form={form}
          presets={presets}
          workers={others}
          onPick={onPickPreset}
        />

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
