"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { runtimeFormSchema } from "@/lib/schemas/deploy-history";
import { updateApplicationRuntime } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from "@/components/ui/card";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { Input } from "@/components/ui/input";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

/**
 * How the site's process is started.
 *
 * These two were only ever askable on the create form, and the API has taken
 * them on `PUT /applications/{id}` the whole time — so a site created with the
 * wrong entry file could not be corrected at all. It started, died with
 * MODULE_NOT_FOUND, and every deploy failed at `verify` with no field anywhere
 * to fix. Deleting the site and making it again was the only route.
 *
 * Saved here, applied by the next deploy: the deployer rewrites the systemd
 * unit before restarting, and the card says so rather than implying the
 * running process changes under you.
 */
export function RuntimeCard({ application, canManage }) {
  const t = useTranslations("applications.deployment.runtime");
  const router = useRouter();
  const [saving, setSaving] = useState(false);

  const defaults = {
    start_command: application.start_command ?? "",
    // Empty rather than 0: the API reads a blank port as "pick a free one",
    // and a 0 in the box would read as a real choice.
    app_port: application.app_port ? String(application.app_port) : "",
  };

  const form = useForm({
    resolver: zodResolver(runtimeFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  useWatchUnsaved("app-runtime", form.formState.isDirty);

  async function save(values) {
    setSaving(true);
    try {
      await updateApplicationRuntime(application.id, {
        start_command: values.start_command.trim(),
        app_port: values.app_port ? Number(values.app_port) : null,
      });
      toast.success(t("saved"));
      form.reset(values);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form, () =>
        toast.error(apiMessage(error, t("saveFailed"))),
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
    <Form {...form}>
      <form onSubmit={form.handleSubmit(save)}>
        <Card className="gap-0 overflow-hidden py-0">
          <CardHeader className="px-5 pt-5 pb-0">
            <CardTitle className="text-base font-semibold">{t("title")}</CardTitle>
            <CardDescription>{t("subtitle")}</CardDescription>
          </CardHeader>

          <CardContent className="@container p-5">
            {/* The same grid the create form gives these two fields, so they
                do not look like different controls in the two places they
                appear: equal columns, container query rather than viewport, and
                `items-start` so the two hints — different lengths — do not
                stretch each other. */}
            <div className="grid grid-cols-1 items-start gap-4 @2xl:grid-cols-2">
              <FormField
                control={form.control}
                name="start_command"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel required>{t("startCommand")}</FormLabel>
                    <FormControl>
                      <Input
                        {...field}
                        className="font-mono"
                        autoComplete="off"
                        spellCheck={false}
                        placeholder="node index.js"
                        disabled={!canManage || saving}
                      />
                    </FormControl>
                    <FormDescription>{t("startCommandHint")}</FormDescription>
                    <FormMessage field={t("startCommand")} />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="app_port"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel>{t("appPort")}</FormLabel>
                    <FormControl>
                      <Input
                        {...field}
                        inputMode="numeric"
                        className="font-mono"
                        autoComplete="off"
                        placeholder={t("appPortPlaceholder")}
                        disabled={!canManage || saving}
                      />
                    </FormControl>
                    <FormDescription>{t("appPortHint")}</FormDescription>
                    <FormMessage field={t("appPort")} />
                  </FormItem>
                )}
              />
            </div>
          </CardContent>

          <CardSaveFooter
            saving={saving}
            dirty={form.formState.isDirty}
            saveReason={
              !canManage
                ? t("noPermission")
                : !form.formState.isDirty
                  ? t("nothingToSave")
                  : null
            }
            note={t("appliesOnDeploy")}
            saveLabel={t("save")}
            submit
            onDiscard={() => form.reset(defaults)}
          />
        </Card>
      </form>
    </Form>
    </DisabledReasonProvider>
  );
}
