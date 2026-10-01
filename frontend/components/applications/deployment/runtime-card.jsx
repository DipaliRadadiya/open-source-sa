import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Play } from "lucide-react";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { runtimeFormSchema } from "@/lib/schemas/deploy-history";
import { updateApplicationRuntime } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Row, Section, SectionActions } from "@/components/settings/setting-row";
import { Input } from "@/components/ui/input";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import {
  Form,
  FormField,
} from "@/components/ui/form";

/**
 * How the site's process is started, editable after creation via
 * `PUT /applications/{id}`.
 *
 * Saved here, applied by the next deploy: the deployer rewrites the systemd unit
 * before restarting, and the card says so.
 */
export function RuntimeCard({ application, canManage }) {
  const t = useTranslations("applications.deployment.runtime");
  const { refreshAndWait } = useRefresh();
  const [saving, setSaving] = useState(false);

  const defaults = {
    start_command: application.start_command ?? "",
    // Empty rather than 0: the API reads a blank port as "pick a free one".
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
      form.reset(values);
      await refreshAndWait();
      toast.success(t("saved"));
    } catch (error) {
      if (error.response?.data?.errors) handleValidationError(error, form);
      else toast.error(apiMessage(error, t("saveFailed")));
    } finally {
      setSaving(false);
    }
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Form {...form}>
        <form noValidate onSubmit={form.handleSubmit(save)}>
          {/* Same Section/Row layout as the card above. A value that fits the 224px control
              column gets a row; a shell command or script goes full width. */}
          <Section
            icon={Play}
            title={t("title")}
            description={t("subtitle")}
            readOnly={!canManage}
            actions={
              <SectionActions
                label={t("save")}
                isDirty={form.formState.isDirty}
                pending={saving}
                onDiscard={() => form.reset(defaults)}
                canManage={canManage}
              />
            }
          >
            <FormField
              control={form.control}
              name="start_command"
              render={({ field }) => (
                <Row wide required label={t("startCommand")} hint={t("startCommandHint")}>
                  <Input
                    {...field}
                    className="font-mono"
                    autoComplete="off"
                    spellCheck={false}
                    placeholder="node index.js"
                    disabled={!canManage || saving}
                  />
                </Row>
              )}
            />
            <FormField
              control={form.control}
              name="app_port"
              render={({ field }) => (
                <Row wide label={t("appPort")} hint={t("appPortHint")}>
                  <Input
                    {...field}
                    inputMode="numeric"
                    className="font-mono"
                    autoComplete="off"
                    placeholder={t("appPortPlaceholder")}
                    disabled={!canManage || saving}
                  />
                </Row>
              )}
            />
          </Section>
        </form>
      </Form>
    </DisabledReasonProvider>
  );
}
