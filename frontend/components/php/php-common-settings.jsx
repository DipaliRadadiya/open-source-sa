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
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { LoadFailed } from "@/components/data-table/load-failed";
import { SettingsCardSkeleton } from "@/components/settings/settings-skeleton";
import { Row, Section, SectionActions } from "@/components/settings/setting-row";

// Told to the php.ini tab (and back) so neither saves over the other's change.
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

/**
 * The common php.ini settings of one version as fields (RC-8), so nobody has to edit
 * the file for a memory or upload limit. The file stays on its own tab.
 */
export function PhpCommonSettings({ version, canManage }) {
  const t = useTranslations("php.common");
  const [state, setState] = useState({ loading: true, settings: null, error: null });

  // State changes only once the answer is in, so this can run from an effect.
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

  // The php.ini tab saved the file: what these fields show may have changed.
  useEffect(() => {
    const onChange = (event) => {
      if (event.detail?.version === version && event.detail?.source === "ini") load();
    };
    window.addEventListener(PHP_INI_CHANGED, onChange);
    return () => window.removeEventListener(PHP_INI_CHANGED, onChange);
  }, [version, load]);

  if (state.loading) return <SettingsCardSkeleton rows={5} />;
  if (state.error) return <LoadFailed description={t("loadFailed")} status={state.status} failure={state.failure} message={state.error} />;

  // Keyed on what was loaded, so a reload resets the form to the file.
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
  const tPhp = useTranslations("php");
  const defaults = toForm(settings);
  const [pending, setPending] = useState(false);

  const form = useForm({
    resolver: zodResolver(phpCommonSettingsFormSchema),
    defaultValues: defaults,
    mode: "onBlur",
  });
  const upload = useWatch({ control: form.control, name: "upload_max_filesize" });

  async function save(values) {
    // Only what changed and is filled in: empty means "leave the file as it is".
    const payload = {};
    for (const key of FIELDS) {
      // The resolver coerces the numbers, so compare as text.
      const value = String(values[key] ?? "");
      if (value !== defaults[key] && value !== "") {
        payload[key] = key.startsWith("max_") ? Number(values[key]) : values[key];
      }
    }
    // One upload field: post_max_size must be at least as large, or uploads that size
    // still fail. Raised to match, never lowered.
    if (payload.upload_max_filesize && phpSizeToBytes(settings.post_max_size ?? "8M") < phpSizeToBytes(payload.upload_max_filesize)) {
      payload.post_max_size = payload.upload_max_filesize;
    }
    if (Object.keys(payload).length === 0) return;

    setPending(true);
    try {
      const { data } = await savePhpSettings(version, payload);
      const parsed = phpCommonSettingsSchema.safeParse(data);
      toast.success(t("saved", { version }));
      window.dispatchEvent(new CustomEvent(PHP_INI_CHANGED, { detail: { version, source: "settings" } }));
      onSaved(parsed.success ? parsed.data.settings : { ...settings, ...payload });
    } catch (error) {
      if (!handleValidationError(error, form)) toast.error(apiMessage(error, t("failed")));
    } finally {
      setPending(false);
    }
  }

  const notSet = t("notSet");
  const directive = (name) => <code className="ms-1.5 font-mono text-xs font-normal text-muted-foreground">{name}</code>;
  const raisesPost =
    upload && /^(-1|\d+[KMG]?)$/i.test(upload) && phpSizeToBytes(settings.post_max_size ?? "8M") < phpSizeToBytes(upload);

  return (
    <DisabledReasonProvider reason={canManage ? null : tPhp("noPermission")}>
    <Form {...form}>
      <form noValidate onSubmit={form.handleSubmit(save)}>
        <Section
          icon={SlidersHorizontal}
          title={t("title", { version })}
          description={t("description", { version })}
          readOnly={!canManage}
          changedBy={canManage ? t("appliesNote") : null}
          actions={
            <SectionActions
              label={t("saveAction")}
              isDirty={form.formState.isDirty}
              pending={pending}
              onDiscard={() => form.reset(defaults)}
              canManage={canManage}
            />
          }
        >
          <FormField
            control={form.control}
            name="memory_limit"
            render={({ field }) => (
              <Row label={<>{tApp("fields.memory")}{directive("memory_limit")}</>} hint={tApp("hints.memory")}>
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
                label={<>{tApp("fields.upload")}{directive("upload_max_filesize")}</>}
                hint={raisesPost ? t("uploadRaisesPost", { size: upload }) : t("uploadHint", { post: settings.post_max_size ?? "8M" })}
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
              <Row label={<>{tApp("fields.executionTime")}{directive("max_execution_time")}</>} hint={tApp("hints.executionTime")}>
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
              <Row label={<>{tApp("fields.inputTime")}{directive("max_input_time")}</>} hint={tApp("hints.inputTime")}>
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
              <Row label={<>{tApp("fields.inputVars")}{directive("max_input_vars")}</>} hint={tApp("hints.inputVars")}>
                <FormControl>
                  <Input inputMode="numeric" placeholder={notSet} disabled={!canManage} className="font-mono" {...field} />
                </FormControl>
              </Row>
            )}
          />
        </Section>
      </form>
    </Form>
    </DisabledReasonProvider>
  );
}
