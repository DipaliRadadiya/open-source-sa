"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Pencil, ChevronDown } from "lucide-react";
import { workerFormSchema } from "@/lib/schemas/worker";
import { updateWorker } from "@/lib/api/workers";
import { handleValidationError } from "@/lib/api/handle-validation-error";
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

function valuesFrom(worker) {
  return {
    name: worker.name,
    command: worker.command,
    kind: worker.kind,
    processes: worker.processes,
    directory: worker.directory ?? "",
    stop_wait_seconds: worker.stop_wait_seconds ?? 30,
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

  useEffect(() => {
    if (open) form.reset(valuesFrom(worker));
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, worker]);

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
    const payload = {
      ...values,
      name: values.name.trim(),
      command: values.command.trim(),
      directory: values.directory?.trim() || undefined,
    };

    try {
      await updateWorker(appId, worker.id, payload);
      toast.success(t("toast.updated"));
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

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
        onSubmit={form.handleSubmit(onSubmit)}
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
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t("form.name")}</FormLabel>
              <FormControl>
                <Input placeholder={t("form.namePlaceholder")} autoComplete="off" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />

        <WorkerCommandField
          form={form}
          presets={presets}
          workers={workers.filter((w) => w.id !== worker.id)}
          onPick={onPickPreset}
        />

        <FormField
          control={form.control}
          name="processes"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t("form.processes")}</FormLabel>
              <FormControl>
                <Input
                  type="number"
                  inputMode="numeric"
                  min={1}
                  max={16}
                  className="w-24"
                  {...field}
                />
              </FormControl>
              <p className="text-xs text-muted-foreground">{t("form.processesHint")}</p>
              <FormMessage />
            </FormItem>
          )}
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
                  <FormLabel>{t("form.stopWaitSeconds")}</FormLabel>
                  <FormControl>
                    <Input type="number" inputMode="numeric" min={1} max={300} className="w-24" {...field} />
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
