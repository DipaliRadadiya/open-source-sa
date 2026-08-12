"use client";

import { useState } from "react";
import { useRouter } from "next/navigation";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { RotateCcw } from "lucide-react";
import { deploySettingsFormSchema } from "@/lib/schemas/deploy-history";
import { updateDeploySettings } from "@/lib/api/deployment";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
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
 * What a deploy actually does — and, until now, the part of it nobody could
 * change after the site was created.
 *
 * The create form accepts a deploy script; there was no screen to edit one
 * afterwards, so a script that turned out wrong meant recreating the site.
 *
 * `deploy_script` comes back filled even when the user has written nothing —
 * it falls back to the old build command. `deploy_script_customised` is what
 * separates "their script" from "the fallback", and it is the difference
 * between offering Reset and pretending someone else's text is theirs.
 */
export function DeploySettingsCard({ applicationId, settings, canManage }) {
  const t = useTranslations("applications.deployment.settings");
  const router = useRouter();
  const [saving, setSaving] = useState(false);

  const form = useForm({
    resolver: zodResolver(deploySettingsFormSchema),
    mode: "onBlur",
    defaultValues: {
      branch: settings.branch ?? "main",
      deploy_script: settings.deploy_script ?? "",
    },
  });

  const script = useWatch({ control: form.control, name: "deploy_script" });
  const isDefault = script === (settings.default_deploy_script ?? "");

  async function save(values) {
    setSaving(true);
    try {
      await updateDeploySettings(applicationId, values);
      toast.success(t("saved"));
      form.reset(values);
      router.refresh();
    } catch (error) {
      if (error.response?.data?.errors) handleValidationError(error, form);
      else toast.error(apiMessage(error, t("saveFailed")));
    } finally {
      setSaving(false);
    }
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(save)}>
        <Card className="gap-0 overflow-hidden py-0 shadow-sm">
          <CardContent className="space-y-5 px-5 py-5">
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="branch"
                render={({ field }) => (
                  <FormItem className="min-w-0">
                    <FormLabel>{t("branch")}</FormLabel>
                    <FormControl>
                      <Input {...field} disabled={!canManage || saving} className="font-mono text-sm" />
                    </FormControl>
                    <FormDescription>{t("branchHint")}</FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />

            </div>

            <FormField
              control={form.control}
              name="deploy_script"
              render={({ field }) => (
                <FormItem className="min-w-0">
                  <div className="flex min-h-6 flex-wrap items-center justify-between gap-2">
                    <FormLabel>{t("script")}</FormLabel>
                    {/* Only worth offering once it differs from the default —
                        otherwise it is a button that does nothing. */}
                    {canManage && settings.default_deploy_script && !isDefault ? (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-6 gap-1.5 px-2 text-xs"
                        onClick={() =>
                          form.setValue("deploy_script", settings.default_deploy_script, {
                            shouldDirty: true,
                          })
                        }
                      >
                        <RotateCcw className="size-3" />
                        {t("resetScript")}
                      </Button>
                    ) : null}
                  </div>
                  <FormControl>
                    <Textarea
                      {...field}
                      rows={10}
                      spellCheck={false}
                      disabled={!canManage || saving}
                      className="font-mono text-xs"
                    />
                  </FormControl>
                  <FormDescription>
                    {settings.deploy_script_customised ? t("scriptHint") : t("scriptFallbackHint")}
                    {settings.placeholders?.length ? (
                      <span className="mt-1 block">
                        {t("placeholders")}{" "}
                        {settings.placeholders.map((token) => (
                          <code
                            key={token}
                            className="mr-1 rounded bg-muted px-1 py-0.5 font-mono text-[11px]"
                          >
                            {token}
                          </code>
                        ))}
                      </span>
                    ) : null}
                  </FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
          </CardContent>

          <CardSaveFooter
            submit
            saving={saving}
            dirty={form.formState.isDirty}
            saveReason={
              !canManage ? t("noPermission") : !form.formState.isDirty ? t("nothingToSave") : null
            }
            onDiscard={() =>
              form.reset({
                branch: settings.branch ?? "main",
                deploy_script: settings.deploy_script ?? "",
              })
            }
            saveLabel={t("save")}
            note={t("saveNote")}
          />
        </Card>
      </form>
    </Form>
  );
}
