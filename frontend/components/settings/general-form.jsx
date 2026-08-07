"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Server, TriangleAlert } from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { generalFormSchema } from "@/lib/schemas/settings";
import { updateGeneralSettings } from "@/lib/api/settings";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { validationMessage } from "@/lib/settings/validation-message";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { Form, FormField, FormControl } from "@/components/ui/form";
import {
  Row,
  Section,
  SectionActions,
} from "@/components/settings/setting-row";
import { TimezoneField } from "@/components/settings/timezone-field";

export function GeneralForm({ general, canManage, timezones = [], changedBy }) {
  const t = useTranslations("settings.server");
  const tv = useTranslations("settings.validation");
  const router = useRouter();

  const defaults = {
    hostname: general?.hostname ?? "",
    timezone: general?.timezone ?? "UTC",
    ntp: general?.ntp ?? true,
  };

  const form = useForm({
    resolver: zodResolver(generalFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  async function onSubmit(values) {
    try {
      await updateGeneralSettings(values);
      toast.success(t("saved"));
      form.reset(values);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
        <Section
          icon={Server}
          title={t("title")}
          description={t("description")}
          readOnly={!canManage}
          changedBy={changedBy}
          actions={
            <SectionActions
              label={t("save")}
              isDirty={form.formState.isDirty}
              pending={form.formState.isSubmitting}
              onDiscard={() => form.reset(defaults)}
              canManage={canManage}
            />
          }
        >
          <FormField
            control={form.control}
            name="hostname"
            render={({ field }) => (
              <Row
                label={t("hostname")}
                hint={t("hostnameHint")}
                required
                error={validationMessage(
                  tv,
                  form.formState.errors.hostname?.message,
                )}
              >
                <FormControl>
                  <Input
                    placeholder="server.example.com"
                    className="font-mono"
                    autoComplete="off"
                    spellCheck={false}
                    disabled={!canManage}
                    {...field}
                  />
                </FormControl>
              </Row>
            )}
          />

          <FormField
            control={form.control}
            name="timezone"
            render={({ field }) => (
              <Row label={t("timezone")} hint={t("timezoneHint")}>
                <TimezoneField
                  value={field.value}
                  onChange={field.onChange}
                  disabled={!canManage}
                  groups={timezones}
                />
              </Row>
            )}
          />

          <FormField
            control={form.control}
            name="ntp"
            render={({ field }) => (
              <Row
                label={t("ntp")}
                hint={
                  general?.clock_synchronized === false && general?.ntp
                    ? t("clockDriftHint")
                    : t("ntpHint")
                }
              >
                <div className="flex items-center gap-2">
                  <FormControl>
                    <Switch
                      checked={field.value}
                      onCheckedChange={field.onChange}
                      disabled={!canManage}
                    />
                  </FormControl>
                  {general?.clock_synchronized === false && general?.ntp ? (
                    <Badge variant="warning" className="gap-1 font-normal">
                      <TriangleAlert className="size-3" />
                      {t("clockDrift")}
                    </Badge>
                  ) : null}
                </div>
              </Row>
            )}
          />
        </Section>
      </form>
    </Form>
  );
}
