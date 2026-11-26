"use client";

import { useCallback, useEffect, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { SlidersHorizontal } from "lucide-react";
import { readPhpSettings, savePhpSettings } from "@/lib/api/php";
import { phpCommonSettingsFormSchema, phpCommonSettingsSchema } from "@/lib/schemas/php";
import { phpSizeToBytes } from "@/lib/schemas/php-settings";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Form, FormControl, FormField } from "@/components/ui/form";
import { Input } from "@/components/ui/input";
import { LabelHint } from "@/components/ui/label";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { LoadFailed } from "@/components/data-table/load-failed";
import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";
import { Row, Section, SectionActions } from "@/components/settings/setting-row";
import { Caution } from "@/components/ui/caution";
import { SafetyCopySwitch } from "@/components/backups/safety-copy-switch";

export const PHP_INI_CHANGED = "php-ini-changed";

const FIELDS = ["memory_limit", "upload_max_filesize", "max_execution_time", "max_input_time", "max_input_vars"];
const toForm = (settings) =>
  Object.fromEntries(FIELDS.map((key) => [key, settings[key] === null || settings[key] === undefined ? "" : String(settings[key])]));

async function fetchSettings(version, failedText) {
  try {
    const { data } = await readPhpSettings(version);
    const parsed = phpCommonSettingsSchema.safeParse(data);
    return parsed.success
      ? { loading: false, settings: parsed.data.settings, error: null }
      : { loading: false, settings: null, error: failedText, failure: "shape" };
  } catch (error) {
    return {
      loading: false,
      settings: null,
      error: apiMessage(error, failedText),
      status: error.response?.status,
      failure: error.response ? "http" : "network",
    };
  }
}

export function PhpCommonSettings({ version, canManage }) {
  const t = useTranslations("php.common");
  const [state, setState] = useState({ loading: true, settings: null, error: null });

  const load = useCallback(
    () =>
      fetchSettings(version, t("loadFailed")).then((result) => {
        setState(result);
      }),
    [version, t],
  );

  useEffect(() => {
    let active = true;
    fetchSettings(version, t("loadFailed")).then((result) => active && setState(result));
    return () => {
      active = false;
    };
  }, [version, t]);

  useEffect(() => {
    const onChange = (event) => {
      if (event.detail?.version === version && event.detail?.source === "ini") load();
    };
    window.addEventListener(PHP_INI_CHANGED, onChange);
    return () => window.removeEventListener(PHP_INI_CHANGED, onChange);
  }, [version, load]);

  if (state.loading) return <SettingsCardSkeleton rows={5} />;
  if (state.error) return <LoadFailed description={t("loadFailed")} status={state.status} failure={state.failure} message={state.error} />;

  return (
    <SettingsForm
      key={JSON.stringify(state.settings)}
      version={version}
      settings={state.settings}
      canManage={canManage}
      onSaved={(settings) => setState({ loading: false, settings, error: null })}
    />
  );
}

function SettingsForm({ version, settings, canManage, onSaved }) {
  const t = useTranslations("php.common");
  const tApp = useTranslations("applications.php");
  const defaults = toForm(settings);
  const [pending, setPending] = useState(false);
  const [safetyCopy, setSafetyCopy] = useState(true);
  const tc = useTranslations("common");

  const form = useForm({
    resolver: zodResolver(phpCommonSettingsFormSchema),
    defaultValues: defaults,
    mode: "onBlur",
  });
  const upload = useWatch({ control: form.control, name: "upload_max_filesize" });

  async function save(values) {
    const payload = {};
    for (const key of FIELDS) {
      const value = String(values[key] ?? "");
      if (value !== defaults[key] && value !== "") {
        payload[key] = key.startsWith("max_") ? Number(values[key]) : values[key];
      }
    }
    // post_max_size must be at least the upload size, or those uploads still fail.
    if (payload.upload_max_filesize && phpSizeToBytes(settings.post_max_size ?? "8M") < phpSizeToBytes(payload.upload_max_filesize)) {
      payload.post_max_size = payload.upload_max_filesize;
    }
    if (Object.keys(payload).length === 0) return;

    setPending(true);
    try {
      const { data } = await savePhpSettings(version, payload, { safetyCopy });
      const parsed = phpCommonSettingsSchema.safeParse(data);
      toast.success(t("saved", { version }));
      setSafetyCopy(true);
      window.dispatchEvent(new CustomEvent(PHP_INI_CHANGED, { detail: { version, source: "settings" } }));
      onSaved(parsed.success ? parsed.data.settings : { ...settings, ...payload });
    } catch (error) {
      handleValidationError(error, form, { fallback: t("failed") });
    } finally {
      setPending(false);
    }
  }

  const notSet = t("notSet");
  const label = (field, name, explain) => (
    <span className="flex flex-wrap items-center gap-x-2 gap-y-1">
      <span>{tApp(field)}</span>
      <span className="inline-flex items-center gap-1">
        <code className="font-mono text-xs font-normal text-muted-foreground">{name}</code>
        <LabelHint>{explain}</LabelHint>
      </span>
    </span>
  );
  const raisesPost =
    upload && /^(-1|\d+[KMG]?)$/i.test(upload) && phpSizeToBytes(settings.post_max_size ?? "8M") < phpSizeToBytes(upload);

  return (
    <DisabledReasonProvider denied={!canManage}>
    <Form {...form}>
      <form noValidate onSubmit={form.handleSubmit(save)}>
        <Section
          icon={SlidersHorizontal}
          title={t("title", { version })}
          readOnly={!canManage}
          actions={
            <SectionActions
              label={t("saveAction")}
              isDirty={form.formState.isDirty}
              pending={pending}
              onDiscard={() => {
                form.reset(defaults);
                setSafetyCopy(true);
              }}
              canManage={canManage}
            />
          }
        >
          <FormField
            control={form.control}
            name="memory_limit"
            render={({ field }) => (
              <Row label={label("fields.memory", "memory_limit", tApp("hints.memory"))}>
                <FormControl>
                  <Input placeholder={notSet} disabled={!canManage} className="font-mono" {...field} />
                </FormControl>
              </Row>
            )}
          />
          <FormField
            control={form.control}
            name="upload_max_filesize"
            render={({ field }) => (
              <Row
                label={label("fields.upload", "upload_max_filesize", t("uploadHint", { post: settings.post_max_size ?? "8M" }))}
                hint={raisesPost ? t("uploadRaisesPost", { size: upload }) : null}
              >
                <FormControl>
                  <Input placeholder={notSet} disabled={!canManage} className="font-mono" {...field} />
                </FormControl>
              </Row>
            )}
          />
          <FormField
            control={form.control}
            name="max_execution_time"
            render={({ field }) => (
              <Row label={label("fields.executionTime", "max_execution_time", tApp("hints.executionTime"))}>
                <FormControl>
                  <Input inputMode="numeric" placeholder={notSet} disabled={!canManage} className="font-mono" {...field} />
                </FormControl>
              </Row>
            )}
          />
          <FormField
            control={form.control}
            name="max_input_time"
            render={({ field }) => (
              <Row label={label("fields.inputTime", "max_input_time", tApp("hints.inputTime"))}>
                <FormControl>
                  <Input inputMode="numeric" placeholder={notSet} disabled={!canManage} className="font-mono" {...field} />
                </FormControl>
              </Row>
            )}
          />
          <FormField
            control={form.control}
            name="max_input_vars"
            render={({ field }) => (
              <Row label={label("fields.inputVars", "max_input_vars", tApp("hints.inputVars"))}>
                <FormControl>
                  <Input inputMode="numeric" placeholder={notSet} disabled={!canManage} className="font-mono" {...field} />
                </FormControl>
              </Row>
            )}
          />
          {canManage && form.formState.isDirty ? (
            <div className="space-y-2">
              <SafetyCopySwitch
                id={`settings-safety-copy-${version}`}
                compact
                checked={safetyCopy}
                onCheckedChange={setSafetyCopy}
                disabled={pending}
                label={tc("keepSettingsCopy")}
              />
              {!safetyCopy ? (
                <Caution tone="destructive">
                  <p>{tc("keepSettingsCopyOff")}</p>
                </Caution>
              ) : null}
            </div>
          ) : null}
        </Section>
      </form>
    </Form>
    </DisabledReasonProvider>
  );
}
