"use client";

import { createContext, useContext, useState } from "react";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { toast } from "sonner";
import {
  Cpu,
  FileUp,
  Info,
  Loader2,
  Lock,
  MemoryStick,
  RotateCcw,
  Timer,
  User,
  X,
  ShieldCheck,
  TriangleAlert,
  Settings2,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { budgetWith, phpSettingsFormSchemaFor, phpSizeToBytes } from "@/lib/schemas/php-settings";
import {
  isolateApplicationPhp,
  resetApplicationPhpFields,
  updateApplicationPhp,
} from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { rangeLabel, versionWithin } from "@/lib/runtime/version-range";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Badge } from "@/components/ui/badge";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { Combobox } from "@/components/ui/combobox";
import { phpTimezoneOptionsWith } from "@/lib/settings/timezone-options";
import { Input } from "@/components/ui/input";
import { LabelHint } from "@/components/ui/label";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import { Textarea } from "@/components/ui/textarea";
import { Tabs, TabsContent, TabsList, TabsTrigger } from "@/components/ui/tabs";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

/** Common values, so most edits are a pick rather than typing. */
const UPLOAD_SIZES = ["8M", "32M", "64M", "128M", "256M", "512M"];
const MEMORY_SIZES = ["128M", "256M", "512M", "1G"];
const EXECUTION_TIMES = [30, 60, 120, 300];
const INPUT_VARS = [1000, 3000, 5000, 10000];
// 10 is the default pool size; without it unconfigured sites open in Custom.
const WORKERS = [2, 4, 6, 8, 10, 12];

const TABS = [
  { key: "basic", icon: Cpu },
  { key: "security", icon: ShieldCheck },
  { key: "advanced", icon: Settings2 },
];

// Marks a change behind a hidden tab. Presets and the upload limit also write Advanced fields.
const TAB_FIELDS = {
  basic: [
    "php_version",
    "memory_limit",
    "pm_max_children",
    "upload_max_filesize",
    "max_execution_time",
    "max_input_vars",
  ],
  security: [
    "open_basedir_enabled",
    "open_basedir_paths",
    "allow_url_fopen",
    "disable_functions",
  ],
  advanced: [
    "pm_type",
    "pm_max_requests",
    "max_input_time",
    "session_gc_maxlifetime",
    "php_timezone",
    "auto_prepend_file",
    "post_max_size",
    "additional_directives",
  ],
};

export function PhpPanel({ appId, php, phpRange = null, siteTypeTitle = "", applicationPath = "", timezones = [], canManage }) {
  const t = useTranslations("applications.php");
  const { refreshAndWait } = useRefresh();
  const [saving, setSaving] = useState(false);
  const [busy, setBusy] = useState(false);


  async function isolate() {
    setBusy(true);
    try {
      await isolateApplicationPhp(appId);
      await refreshAndWait();
      toast.success(t("isolation.isolated"));
    } catch (error) {
      toast.error(apiMessage(error, t("isolation.failed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <div className="space-y-4">
        <IsolationCard php={php} canManage={canManage} busy={busy} onIsolate={isolate} />
  
        {php.isolated && php.managed === false ? (
          <p className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
            <span>{t("unmanaged")}</span>
          </p>
        ) : php.isolated && php.managed === null ? (
          // Unknown is neither answer, so no warning about hand edits.
          <p className="flex items-start gap-2.5 rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
            <Info className="mt-0.5 size-4 shrink-0" />
            <span>{t("managedUnknown")}</span>
          </p>
        ) : null}
  
        {php.isolated ? (
          <DedicatedPhpPanel
            appId={appId}
            php={php}
            phpRange={phpRange}
            siteTypeTitle={siteTypeTitle}
            applicationPath={applicationPath}
            timezones={timezones}
            canManage={canManage}
            saving={saving}
            setSaving={setSaving}
          />
        ) : (
          <SharedPhpState
            php={php}
            phpRange={phpRange}
            siteTypeTitle={siteTypeTitle}
            canManage={canManage}
            busy={busy}
            onIsolate={isolate}
          />
        )}
      </div>
    </DisabledReasonProvider>
  );
}

// ─── Shared PHP mode ────────────────────────────────────────────────────────

function SharedPhpState({ php, phpRange = null, siteTypeTitle = "", canManage, busy, onIsolate }) {
  const t = useTranslations("applications.php");
  const tShared = useTranslations("applications.php.shared");
  const tIsolation = useTranslations("applications.php.isolation");
  const { refreshAndWait } = useRefresh();
  const settings = php.settings;

  // `picked` only overrides the server value until they match, so an external change is not a stale edit.
  const [picked, setPicked] = useState(null);
  const serverVersion = php.php_version ?? "";
  const version = picked !== null && picked !== serverVersion ? picked : serverVersion;
  const setVersion = setPicked;
  const [savingVersion, setSavingVersion] = useState(false);
  const versions = php.available_versions ?? [];
  const versionChanged = version !== serverVersion;
  // Whether the selected version is one this application supports.
  const unsupportedVersion =
    Boolean(phpRange) && Boolean(version) && !versionWithin(version, phpRange);

  async function saveVersion() {
    setSavingVersion(true);
    try {
      // Only the version: settings need a pool file, so sending them 422s.
      await updateApplicationPhp(php.application_id, { php_version: version });
      await refreshAndWait();
      toast.success(t("saved"));
    } catch (error) {
      toast.error(apiMessage(error, t("saveFailed")));
    } finally {
      setSavingVersion(false);
    }
  }

  return (
    <Card className="overflow-hidden">
      <CardContent className="p-0">
        {!php.isolation_supported ? (
          <p className="flex items-start gap-2.5 border-b px-5 py-3 text-sm">
            <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <span>
              <span className="font-medium">{tIsolation("unsupportedTitle")}</span>{" "}
              <span className="text-muted-foreground">{tIsolation("unsupportedBody")}</span>
            </span>
          </p>
        ) : null}

        {/* Border only when the preview block follows; otherwise it leaves an empty band. */}
        <div className={cn("bg-muted/20 px-5 py-4", php.isolation_supported && "border-b")}>
          <p className="mb-3 text-sm font-medium text-muted-foreground">
            {tShared("currentLabel")}
          </p>
          {/* Same status line as the dedicated panel. */}
          <div className="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs">
            <Stat
              icon={MemoryStick}
              label={tShared("memory")}
              value={settings.memory_limit ?? "—"}
            />
            <Stat
              icon={FileUp}
              label={tShared("upload")}
              value={settings.upload_max_filesize ?? "—"}
            />
            <Stat
              icon={Timer}
              label={tShared("time")}
              value={settings.max_execution_time ? `${settings.max_execution_time}s` : "—"}
            />
            <Stat icon={User} label={tShared("runsAs")} value={php.runs_as ?? "www-data"} />
          </div>
        </div>

        {/* Separate from the read-only strip: the version is editable without a pool. */}
        <div className="flex flex-wrap items-end justify-between gap-3 border-t px-5 py-4">
          <div className="min-w-0 space-y-1.5">
            <p className="text-sm font-medium">{t("fields.version")}</p>
            <Select
              value={version}
              onValueChange={setVersion}
              disabled={!canManage || savingVersion || versions.length === 0}
            >
              <SelectTrigger className="w-44">
                <SelectValue placeholder={php.php_version ?? "—"} />
              </SelectTrigger>
              <SelectContent>
                {versions.map((option) => (
                  <SelectItem key={option} value={option}>
                    {t("versionLabel", { version: option })}
                  </SelectItem>
                ))}
              </SelectContent>
            </Select>
            {unsupportedVersion ? (
              /* The API only checks the version is installed, so an
                 unsupported one saves and breaks the site. */
              <p className="flex items-start gap-1.5 text-xs text-warning">
                <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
                {t("versionUnsupported", {
                  type: siteTypeTitle,
                  range: rangeLabel(phpRange),
                })}
              </p>
            ) : (
              <p className="text-xs text-muted-foreground">{tShared("versionHint")}</p>
            )}
          </div>
          {canManage ? (
            <Button type="button" onClick={saveVersion} disabled={!versionChanged || savingVersion}>
              {savingVersion ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("saveAction")}
            </Button>
          ) : null}
        </div>

        {/* Hidden where the web server has no per-site pools: it can never be unlocked. */}
        {php.isolation_supported ? (
          <div className="border-t px-5 py-4">
            <p className="mb-3 text-xs font-semibold text-muted-foreground">
              {tShared("previewTitle")}
            </p>
            <div className="grid gap-2 sm:grid-cols-2">
              {[
                {
                  icon: Cpu,
                  label: tShared("preview.runtime"),
                },
                {
                  icon: FileUp,
                  label: tShared("preview.limits"),
                },
                {
                  icon: ShieldCheck,
                  label: tShared("preview.security"),
                },
                {
                  icon: Settings2,
                  label: tShared("preview.directives"),
                },
              ].map(({ icon: Icon, label }) => (
                <div key={label} className="flex items-center gap-2 text-sm text-muted-foreground">
                  <Icon className="size-4 shrink-0" />
                  <span>{label}</span>
                </div>
              ))}
            </div>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}

// ─── Dedicated PHP mode ──────────────────────────────────────────────────────

function DedicatedPhpPanel({ appId, php, phpRange = null, siteTypeTitle = "", applicationPath = "", timezones, canManage, saving, setSaving }) {
  const t = useTranslations("applications.php");
  const tCommon = useTranslations("common");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [tab, setTab] = useState("basic");
  // The server's message when it refuses the whole save (`errors.settings`):
  // PHP-FPM rejected the config, or the pool has gone.
  const [rejected, setRejected] = useState(null);
  const settings = php.settings;
  // Lock every control for read-only roles, not just Save.
  const locked = !canManage || saving;
  // Bumped on Discard and after a save: remounts the tabs so per-control UI
  // state (an open "Custom" box, a half-typed function name) resets with the form.
  const [resetKey, setResetKey] = useState(0);

  const defaults = {
    php_version: php.php_version ?? "",
    memory_limit: settings.memory_limit,
    upload_max_filesize: settings.upload_max_filesize,
    post_max_size: settings.post_max_size,
    max_execution_time: settings.max_execution_time,
    max_input_time: settings.max_input_time,
    max_input_vars: settings.max_input_vars,
    session_gc_maxlifetime: settings.session_gc_maxlifetime,
    pm_type: settings.pm_type,
    pm_max_children: settings.pm_max_children,
    pm_max_requests: settings.pm_max_requests,
    open_basedir_enabled: settings.open_basedir_enabled,
    open_basedir_paths: settings.open_basedir_paths ?? "",
    disable_functions: settings.disable_functions ?? "",
    allow_url_fopen: settings.allow_url_fopen,
    php_timezone: settings.php_timezone ?? "",
    auto_prepend_file: settings.auto_prepend_file ?? "",
    additional_directives: settings.additional_directives ?? "",
  };

  const form = useForm({
    resolver: zodResolver(phpSettingsFormSchemaFor(php.memory?.total ?? 0, applicationPath)),
    mode: "onBlur",
    defaultValues: defaults,
  });

  useWatchUnsaved("app-php-settings", form.formState.isDirty);

  const version = useWatch({ control: form.control, name: "php_version" });
  const unsupportedVersion =
    Boolean(phpRange) && Boolean(version) && !versionWithin(version, phpRange);
  const memoryLimit = useWatch({ control: form.control, name: "memory_limit" });
  const maxChildren = useWatch({
    control: form.control,
    name: "pm_max_children",
  });
  const upload = useWatch({
    control: form.control,
    name: "upload_max_filesize",
  });
  const post = useWatch({ control: form.control, name: "post_max_size" });
  const execution = useWatch({
    control: form.control,
    name: "max_execution_time",
  });
  const pmType = useWatch({ control: form.control, name: "pm_type" });

  const budget = budgetWith(php.memory, memoryLimit, maxChildren);

  const { dirtyFields, errors } = form.formState;
  const dirtyTabs = new Set(
    TABS.map(({ key }) => key).filter((key) => TAB_FIELDS[key].some((field) => dirtyFields[field])),
  );
  const errorTabs = TABS.map(({ key }) => key).filter((key) =>
    TAB_FIELDS[key].some((field) => errors[field]),
  );

  // An error on a hidden tab is invisible, so switch to the first tab with one.
  function showErrors(fields) {
    const withErrors = TABS.map(({ key }) => key).filter((key) =>
      TAB_FIELDS[key].some((field) => fields[field]),
    );
    if (withErrors.length === 0) return;
    if (!withErrors.includes(tab)) setTab(withErrors[0]);
    toast.error(t("fixErrors"));
  }

  const postTooSmall = phpSizeToBytes(post) < phpSizeToBytes(upload);

  const activePreset = php.presets.find(
    (preset) => preset.pm_type === pmType && Number(preset.pm_max_children) === Number(maxChildren),
  );

  function setUpload(value) {
    form.setValue("upload_max_filesize", value, { shouldDirty: true });
    if (phpSizeToBytes(value) > phpSizeToBytes(form.getValues("post_max_size"))) {
      form.setValue("post_max_size", value, { shouldDirty: true });
    }
  }

  async function save(values) {
    setSaving(true);
    setRejected(null);
    try {
      // Only changed fields: sending the whole form would turn every inherited default into an override.
      // The version is always sent; the API validates it on every save.
      const payload = Object.fromEntries(
        Object.entries(values).filter(([key]) => key === "php_version" || dirtyFields[key]),
      );
      await updateApplicationPhp(appId, payload);
      form.reset(values);
      setResetKey((key) => key + 1);
      await refreshAndWait();
      toast.success(t("saved"));
    } catch (error) {
      const refused = error.response?.data?.errors;
      // `settings` is the server refusing the whole save (usually PHP-FPM rejecting the config).
      if (refused?.settings) {
        setRejected(refused.settings[0]);
        toast.error(refused.settings[0]);
        router.refresh();
      } else if (refused) {
        handleValidationError(error, form);
        showErrors(refused);
      } else {
        toast.error(apiMessage(error, t("saveFailed")));
      }
    } finally {
      setSaving(false);
    }
  }

  // Only the cleared fields are written, so other in-progress edits stay dirty.
  function onReset(fields, next) {
    const settings = next?.settings;
    if (settings) {
      for (const field of fields) {
        form.setValue(field, settings[field] ?? "", { shouldDirty: false });
      }
    }
    // `overridden` comes from the server-rendered prop.
    router.refresh();
  }

  return (
    <OverrideContext.Provider
      value={{ appId, overridden: php.overridden ?? {}, disabled: !canManage || saving, onReset }}
    >
    <Form {...form}>
      <form
        noValidate
        onSubmit={form.handleSubmit(save, (invalid) => showErrors(invalid))}
        className="space-y-3"
      >
        {/* Tracks the fields, so an unsaved change shows as the value it would become. */}
        <div className="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-lg border bg-muted/30 px-4 py-2.5 text-xs">
          <Stat icon={Cpu} label={t("summary.version")} value={version || "—"} saved={defaults.php_version || "—"} unsavedLabel={tCommon("saveFooter.unsaved")} />
          <Stat icon={MemoryStick} label={t("summary.memory")} value={memoryLimit} saved={defaults.memory_limit} unsavedLabel={tCommon("saveFooter.unsaved")} />
          <Stat icon={FileUp} label={t("summary.upload")} value={upload} saved={defaults.upload_max_filesize} unsavedLabel={tCommon("saveFooter.unsaved")} />
          <Stat
            icon={Timer}
            label={t("summary.time")}
            value={t("seconds", { count: execution })}
            saved={t("seconds", { count: defaults.max_execution_time })}
            unsavedLabel={tCommon("saveFooter.unsaved")}
          />
          <Stat icon={User} label={t("summary.runsAs")} value={php.runs_as ?? "—"} />
        </div>

        <Card className="gap-0 overflow-hidden py-0">
          {/* All tab panels stay mounted (forceMount): unmounting would discard half-typed values. */}
          <Tabs key={resetKey} value={tab} onValueChange={setTab} className="gap-0">
            <div className="border-b px-5 py-3">
              {/* Scrolls rather than wraps, like the Settings tab bar; ScrollFade signals overflow. */}
              <ScrollFade className="-mx-1 px-1 pb-1">
                <TabsList className="!h-auto w-fit gap-1 p-1">
                  {TABS.map(({ key, icon: Icon }) => (
                    <TabsTrigger key={key} value={key} className="gap-2 px-3 py-1.5">
                      <Icon className="size-4" />
                      {t(`tabs.${key}`)}
                      {/* Marks errors or unsaved changes behind a hidden tab. */}
                      {errorTabs.includes(key) ? (
                        <span
                          className="size-1.5 rounded-full bg-destructive"
                          aria-label={t("tabs.errorsHere")}
                        />
                      ) : dirtyTabs.has(key) ? (
                        <span
                          className="size-1.5 rounded-full bg-warning"
                          aria-label={t("tabs.unsavedHere")}
                        />
                      ) : null}
                    </TabsTrigger>
                  ))}
                </TabsList>
              </ScrollFade>
            </div>

            <TabsContent
              value="basic"
              forceMount
              hidden={tab !== "basic"}
              className="space-y-5 px-5 py-5"
            >
              <SectionTitle icon={Cpu} title={t("sections.runtime")} />

              <div className="grid gap-4 sm:grid-cols-2">
                <Stack
                  explain={t("hints.version")}
                  label={t("fields.version")}
                  name="php_version"
                  directive="php_version"
                  // The API only checks the version is installed, so warn here.
                  warning={
                    unsupportedVersion
                      ? t("versionUnsupported", {
                          type: siteTypeTitle,
                          range: rangeLabel(phpRange),
                        })
                      : null
                  }
                >
                  <ValueSelect
                    form={form}
                    name="php_version"
                    disabled={locked}
                    options={php.available_versions}
                    render={(value) => t("versionLabel", { version: value })}
                  />
                </Stack>

                <Stack label={t("fields.memory")} explain={t("hints.memory")} name="memory_limit" directive="memory_limit">
                  <ValueSelect
                    form={form}
                    name="memory_limit"
                    placeholder={t("memoryLimitPlaceholder")}
                    disabled={locked}
                    options={MEMORY_SIZES}
                    customLabel={t("custom")}
                  />
                </Stack>

                <Stack label={t("fields.children")} explain={t("hints.children")} name="pm_max_children" directive="pm.max_children">
                  <ValueSelect
                    form={form}
                    name="pm_max_children"
                    placeholder={t("pmMaxChildrenPlaceholder")}
                    disabled={locked}
                    options={WORKERS}
                    numeric
                    customLabel={t("custom")}
                  />
                </Stack>

                {php.presets.length > 0 ? (
                  <div className="self-start">
                    <Label label={t("presetsLabel")} explain={t("presetsLabelHint")} />
                    <div className="mt-1.5 flex flex-wrap gap-2">
                      {php.presets.map((preset) => (
                        <Button
                          key={preset.key}
                          type="button"
                          variant={activePreset?.key === preset.key ? "default" : "outline"}
                          size="sm"
                          disabled={locked}
                          onClick={() => {
                            form.setValue("pm_type", preset.pm_type, { shouldDirty: true });
                            form.setValue("pm_max_children", preset.pm_max_children, {
                              shouldDirty: true,
                            });
                          }}
                        >
                          {preset.title}
                        </Button>
                      ))}
                    </div>
                    {/* Shown as text, not a `title`, so it is readable on touch. */}
                    <p className="mt-1.5 min-h-4 text-xs text-muted-foreground">
                      {activePreset?.description ?? t("presetsCustom")}
                    </p>
                  </div>
                ) : null}
              </div>

              <MemoryBudget budget={budget} workers={maxChildren} limit={memoryLimit} />

              <div className="border-t pt-5">
                <SectionTitle icon={FileUp} title={t("sections.limits")} />
              </div>

              <div className="grid gap-4 sm:grid-cols-2">
                <Stack
                  label={t("fields.upload")}
                  name="upload_max_filesize"
                  directive="upload_max_filesize + post_max_size"
                  error={postTooSmall ? t("hints.postTooSmall") : null}
                  /* Saving also rewrites the vhost (nginx returns 413 before PHP sees a large upload). */
                  explain={t("hints.upload")}
                >
                  <ValueSelect
                    form={form}
                    name="upload_max_filesize"
                    placeholder={t("uploadMaxFilesizePlaceholder")}
                    disabled={locked}
                    options={UPLOAD_SIZES}
                    onPick={setUpload}
                    customLabel={t("custom")}
                  />
                </Stack>

                <Stack label={t("fields.executionTime")} explain={t("hints.executionTime")} name="max_execution_time" directive="max_execution_time">
                  <ValueSelect
                    form={form}
                    name="max_execution_time"
                    placeholder={t("maxExecutionTimePlaceholder")}
                    disabled={locked}
                    options={EXECUTION_TIMES}
                    render={(value) => t("seconds", { count: value })}
                    numeric
                    customLabel={t("custom")}
                  />
                </Stack>

                <Stack label={t("fields.inputVars")} explain={t("hints.inputVars")} name="max_input_vars" directive="max_input_vars">
                  <ValueSelect
                    form={form}
                    name="max_input_vars"
                    placeholder={t("maxInputVarsPlaceholder")}
                    disabled={locked}
                    options={INPUT_VARS}
                    numeric
                    customLabel={t("custom")}
                  />
                </Stack>
              </div>
            </TabsContent>

            <TabsContent
              value="security"
              forceMount
              hidden={tab !== "security"}
              className="space-y-5 px-5 py-5"
            >
              {/* Not a plain toggle: it has paths, a live value that may differ, and an unknown state. */}
              <OpenBasedir form={form} php={php} disabled={locked} />

              <ToggleRow
                form={form}
                name="allow_url_fopen"
                label={t("fields.allowUrlFopen")}
                directive="allow_url_fopen"
                explain={t("hints.allowUrlFopen")}
                disabled={locked}
              />

              <BlockedFunctions form={form} php={php} disabled={locked} />
            </TabsContent>

            <TabsContent
              value="advanced"
              forceMount
              hidden={tab !== "advanced"}
              className="space-y-5 bg-muted/20 px-5 py-5"
            >
              <p className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                <span>{t("advancedNote")}</span>
              </p>

              <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                  control={form.control}
                  name="pm_type"
                  render={({ field }) => (
                    <FormItem>
                      <Label label={t("fields.pmType")} directive="pm" explain={t("hints.pmType")} />
                      <FormControl>
                        <Combobox
                          options={["ondemand", "dynamic", "static"].map((value) => ({
                            value,
                            label: t(`pmTypes.${value}`),
                          }))}
                          value={field.value}
                          onChange={field.onChange}
                          disabled={locked}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <NumberField
                  form={form}
                  name="pm_max_requests"
                  placeholder={t("pmMaxRequestsPlaceholder")}
                  label={t("fields.maxRequests")}
                  directive="pm.max_requests"
                  explain={t("hints.maxRequests")}
                  disabled={locked}
                  min={0}
                  max={100000}
                />
                <NumberField
                  form={form}
                  name="max_input_time"
                  placeholder={t("maxInputTimePlaceholder")}
                  label={t("fields.inputTime")}
                  directive="max_input_time"
                  explain={t("hints.inputTime")}
                  disabled={locked}
                  min={-1}
                  max={3600}
                />
                <NumberField
                  form={form}
                  name="session_gc_maxlifetime"
                  placeholder={t("sessionGcMaxlifetimePlaceholder")}
                  label={t("fields.sessionLifetime")}
                  directive="session.gc_maxlifetime"
                  explain={t("hints.sessionLifetime")}
                  disabled={locked}
                  min={60}
                  max={604800}
                />

                <FormField
                  control={form.control}
                  name="php_timezone"
                  render={({ field }) => (
                    <FormItem>
                      <Label
                        label={t("fields.timezone")}
                        name="php_timezone"
                        directive="date.timezone"
                        explain={t("hints.timezone")}
                      />
                      <FormControl>
                        <Combobox
                          // `timezones` is grouped by region, not a flat list of strings.
                          options={phpTimezoneOptionsWith(timezones, field.value)}
                          value={field.value}
                          onChange={field.onChange}
                          disabled={locked}
                          placeholder={t("fields.timezonePlaceholder")}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <TextField
                  form={form}
                  name="auto_prepend_file"
                  placeholder={applicationPath ? `${applicationPath.replace(/\/+$/, "")}/prepend.php` : t("autoPrependPlaceholder")}
                  label={t("fields.autoPrepend")}
                  directive="auto_prepend_file"
                  explain={t("hints.autoPrepend")}
                  disabled={locked}
                  mono
                />

                {/* Kept though the upload control writes it: a hand-tuned value
                    may not fit the picker, and hiding it would overwrite it. */}
                <TextField
                  form={form}
                  name="post_max_size"
                  placeholder={t("postMaxSizePlaceholder")}
                  label={t("fields.post")}
                  directive="post_max_size"
                  explain={t("hints.post")}
                  disabled={locked}
                  mono
                />
              </div>

              <FormField
                control={form.control}
                name="additional_directives"
                render={({ field }) => (
                  <FormItem className="border-t pt-5">
                    <Label
                      label={t("fields.directives")}
                      name="additional_directives"
                      explain={t("hints.directives")}
                    />
                    <FormControl>
                      <Textarea
                        {...field}
                        rows={4}
                        spellCheck={false}
                        disabled={locked}
                        className="font-mono text-xs"
                        placeholder={t("fields.directivesPlaceholder")}
                      />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </TabsContent>
          </Tabs>

          {rejected ? (
            <p
              role="alert"
              className="flex items-start gap-2.5 border-t bg-destructive/5 px-5 py-3 text-sm text-destructive"
            >
              <TriangleAlert className="mt-0.5 size-4 shrink-0" />
              <span>{rejected}</span>
            </p>
          ) : null}

          <CardSaveFooter
            submit
            saving={saving}
            dirty={form.formState.isDirty}
            saveReason={
              !canManage ? t("noPermission") : !form.formState.isDirty ? t("nothingToSave") : null
            }
            onDiscard={() => {
              form.reset(defaults);
              setRejected(null);
              setResetKey((key) => key + 1);
            }}
            saveLabel={t("saveAction")}
            note={t("saveNote")}
            showReason
            savingNote={t("savingNote")}
          />
        </Card>
      </form>
    </Form>
    </OverrideContext.Provider>
  );
}

// ─── Reusable primitives ─────────────────────────────────────────────────────

const CUSTOM = "__custom";

function ValueSelect({
  form,
  name,
  options,
  disabled,
  render,
  numeric = false,
  onPick,
  customLabel,
  // Example for the Custom box: formats differ ("256M" vs plain seconds).
  placeholder,
}) {
  const tValidation = useTranslations("validation");
  const tCommon = useTranslations("common");
  const value = useWatch({ control: form.control, name });
  const inList = options.some((option) => String(option) === String(value));
  // Reset by the panel's remount on Discard and after a save.
  const [customPicked, setCustomPicked] = useState(false);
  const custom = !inList || customPicked;

  // Not <FormMessage>: the dropdown branch is outside a <FormField>. Zod emits keys, the API sends sentences.
  const raw = form.formState.errors?.[name]?.message;
  // `requiredField` lives in `common`, not `validation`.
  const error = raw
    ? raw === "requiredField"
      ? tCommon("requiredField")
      : tValidation.has(raw)
        ? tValidation(raw)
        : raw
    : null;

  return (
    <div className="space-y-1.5">
      <Select
        value={custom ? CUSTOM : String(value ?? "")}
        disabled={disabled}
        onValueChange={(next) => {
          if (next === CUSTOM) {
            setCustomPicked(true);
            return;
          }
          setCustomPicked(false);
          const parsed = numeric ? Number(next) : next;
          if (onPick) onPick(parsed);
          else form.setValue(name, parsed, { shouldDirty: true });
        }}
      >
        <FormControl>
          <SelectTrigger className="w-full tabular-nums">
            <SelectValue />
          </SelectTrigger>
        </FormControl>
        <SelectContent>
          {options.map((option) => (
            <SelectItem key={option} value={String(option)} className="tabular-nums">
              {render ? render(option) : option}
            </SelectItem>
          ))}
          {customLabel ? <SelectItem value={CUSTOM}>{customLabel}</SelectItem> : null}
        </SelectContent>
      </Select>

      {custom ? (
        <FormField
          control={form.control}
          name={name}
          render={({ field }) => (
            <FormControl>
              <Input
                {...field}
                // Typed sizes go through onPick too, so post_max_size follows.
                onChange={onPick && !numeric ? (event) => onPick(event.target.value) : field.onChange}
                type={numeric ? "number" : "text"}
                inputMode={numeric ? "numeric" : undefined}
                placeholder={placeholder}
                disabled={disabled}
                className="font-mono tabular-nums"
              />
            </FormControl>
          )}
        />
      ) : null}

      {error ? (
        <p role="alert" className="text-sm text-destructive">
          {error}
        </p>
      ) : null}
    </div>
  );
}

// A changed field shows "saved → new" so an unsaved value is not mistaken for one in effect.
function Stat({ icon: Icon, label, value, saved = value, unsavedLabel }) {
  const pending = saved !== value;
  return (
    <span className="flex items-center gap-1.5">
      <Icon className="size-3.5 shrink-0 text-muted-foreground/70" />
      <span className="text-muted-foreground">{label}</span>
      {pending ? (
        <span className="flex items-center gap-1 font-medium tabular-nums" title={unsavedLabel}>
          <span className="text-muted-foreground line-through decoration-muted-foreground/50">{saved}</span>
          <span aria-hidden className="text-muted-foreground">→</span>
          <span className="text-warning">{value}</span>
          <span className="sr-only">({unsavedLabel})</span>
        </span>
      ) : (
        <span className="font-medium tabular-nums text-foreground">{value}</span>
      )}
    </span>
  );
}

// One-way: the API refuses going back to shared (405), since the shared pool lets one site read every other site's files.
function IsolationCard({ php, canManage, busy, onIsolate }) {
  const t = useTranslations("applications.php.isolation");

  // Nothing to offer; SharedPhpState explains why inside its card.
  if (!php.isolation_supported) return null;

  if (php.isolated) return null;

  return (
    <Card className="gap-0 overflow-hidden border-blue-200 bg-blue-50/60 py-0 dark:border-blue-800 dark:bg-blue-950/20">
      <CardContent className="grid gap-4 px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center">
        <div className="flex min-w-0 items-start gap-3">
          {/* Hidden on the narrowest screens, where it costs a quarter of the column. */}
          <div className="hidden size-9 shrink-0 items-center justify-center rounded-full bg-blue-100 sm:flex dark:bg-blue-900">
            <Lock className="size-4 text-blue-600 dark:text-blue-400" />
          </div>
          <div className="min-w-0 space-y-1.5">
            <p className="flex flex-wrap items-center gap-2 font-semibold">
              {t("offTitle")}
              <Badge
                variant="outline"
                // whitespace-normal: Badge is nowrap by default and this text
                // is long enough to overflow a narrow card.
                className="h-auto border-blue-300 bg-blue-100/60 py-0.5 text-xs font-normal whitespace-normal text-blue-700 dark:border-blue-700 dark:bg-blue-900/60 dark:text-blue-300"
              >
                {t("sharedBadge", { user: php.runs_as ?? "www-data" })}
              </Badge>
            </p>
            <p className="text-sm text-muted-foreground">
              {t("offBody")}
            </p>
            <p className="text-xs text-muted-foreground">{t("isolateHelper")}</p>
          </div>
        </div>

        {canManage ? (
          <Button
            type="button"
            onClick={onIsolate}
            disabled={busy}
            // Wraps: a nowrap button grid item would set the card's minimum width.
            className="h-auto max-w-full justify-self-start py-2 text-center whitespace-normal sm:justify-self-end"
          >
            {busy ? <Loader2 className="size-4 animate-spin" /> : null}
            {t("isolateAction")}
          </Button>
        ) : null}
      </CardContent>
    </Card>
  );
}

/** Memory limit × workers against the server's memory, with an over-commit warning. */
function MemoryBudget({ budget, workers, limit }) {
  const t = useTranslations("applications.php.budget");

  if (!budget.total) return null;

  const pct = (bytes) => Math.min(100, Math.round((bytes / budget.total) * 100));
  const mine = pct(budget.thisSite);
  const others = Math.max(0, Math.min(100 - mine, pct(budget.others)));

  return (
    <div
      className={cn(
        "space-y-2 rounded-lg border p-3",
        budget.overCommitted && "border-destructive/40 bg-destructive/5",
      )}
    >
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="text-sm font-medium tabular-nums">
          {t("equation", {
            // Half-typed or invalid ("abc") reads as unknown, like an empty field.
            limit: /^\d+\s*[KMG]?$/i.test(String(limit ?? "").trim()) ? limit : "—",
            workers: Number(workers) || 0,
            total: formatBytes(budget.thisSite),
          })}
        </p>
        {!budget.overCommitted ? (
          <p className="text-xs text-muted-foreground">
            {t("available", {
              value: formatBytes(budget.available),
              total: formatBytes(budget.total),
            })}
          </p>
        ) : null}
      </div>

      <div className="flex h-2 overflow-hidden rounded-full bg-muted" aria-hidden>
        <div
          className={cn("h-full shrink-0", budget.overCommitted ? "bg-destructive" : "bg-primary")}
          style={{ width: `${mine}%` }}
        />
        <div className="h-full shrink-0 bg-muted-foreground/30" style={{ width: `${others}%` }} />
      </div>

      {budget.overCommitted ? (
        <p className="flex items-start gap-2 text-xs font-medium text-destructive">
          <TriangleAlert className="mt-px size-3.5 shrink-0" />
          <span>
            {t("overDetail", {
              required: formatBytes(budget.committed),
              total: formatBytes(budget.total),
            })}
          </span>
        </p>
      ) : null}

      <p className="text-xs text-muted-foreground">
        {t("legend", {
          sites: Math.max(0, budget.sites - 1),
          value: formatBytes(budget.others),
        })}
      </p>
    </div>
  );
}

function formatBytes(bytes) {
  if (!bytes) return "0 MB";
  const gb = bytes / (1024 * 1024 * 1024);
  if (gb >= 1) return `${Math.round(gb * 10) / 10} GB`;
  return `${Math.round(bytes / (1024 * 1024))} MB`;
}

function Stack({ label, name, directive, error, warning, explain, children }) {
  return (
    <FormItem>
      <Label label={label} name={name} directive={directive} explain={explain} />
      {children}
      {/* Only value-driven messages here; the field's meaning lives behind the ⓘ. */}
      {error ? (
        <p className="text-sm text-destructive">{error}</p>
      ) : warning ? (
        <p className="flex items-start gap-1.5 text-xs text-warning">
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
          {warning}
        </p>
      ) : null}
    </FormItem>
  );
}

/** What the per-field Reset needs, without threading props through each field. */
const OverrideContext = createContext(null);

// `explain` is the only place a field is explained; the hint never restates the directive.
function Label({ label, name, directive, explain }) {
  return (
    <div className="flex min-h-5 items-center justify-between gap-2">
      <FormLabel className="min-w-0 flex-wrap gap-1" hint={directive ? undefined : explain}>
        <span>{label}</span>
        {/* ⓘ grouped with the directive so it never wraps alone; break-all
            lets long directives wrap on narrow columns. */}
        {directive ? (
          <span className="inline-flex min-w-0 items-center gap-1">
            <span className="font-mono text-xs font-normal break-all text-muted-foreground">
              ({directive})
            </span>
            {explain ? <LabelHint>{explain}</LabelHint> : null}
          </span>
        ) : null}
      </FormLabel>
      <ResetOverride name={name} />
    </div>
  );
}

// Saves immediately: the API returns effective values only, so the inherited value comes from the reset response.
function ResetOverride({ name }) {
  const context = useContext(OverrideContext);
  const t = useTranslations("applications.php");
  const [busy, setBusy] = useState(false);

  if (!context || !name || !RESETTABLE.has(name)) return null;
  // Usually just itself; see RESET_FIELDS for controls owning two directives.
  const fields = (RESET_FIELDS[name] ?? [name]).filter((field) => RESETTABLE.has(field));
  if (!fields.some((field) => context.overridden[field])) return null;

  async function reset() {
    setBusy(true);
    try {
      const { data } = await resetApplicationPhpFields(context.appId, fields);
      context.onReset(fields, data?.php);
      toast.success(t("resetDone"));
    } catch (error) {
      toast.error(apiMessage(error, t("resetFailed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Button
      type="button"
      variant="ghost"
      size="sm"
      onClick={reset}
      disabled={busy || context.disabled}
      aria-busy={busy}
      className="-my-1 h-6 gap-1 px-1.5 text-xs font-normal text-muted-foreground hover:text-foreground"
    >
      {busy ? <Loader2 className="size-3 animate-spin" /> : <RotateCcw className="size-3" />}
      {t("reset")}
    </Button>
  );
}

// The upload control writes both sizes, so its Reset must clear both or a stale `post_max_size` breaks uploads.
const RESET_FIELDS = {
  upload_max_filesize: ["upload_max_filesize", "post_max_size"],
};

// `SavePhpSettingsRequest` `nullable` directives (null clears the override). Keep in step with the backend; anything else 422s.
const RESETTABLE = new Set([
  "memory_limit",
  "upload_max_filesize",
  "post_max_size",
  "max_execution_time",
  "max_input_time",
  "max_input_vars",
  "session_gc_maxlifetime",
  "disable_functions",
  "php_timezone",
  "auto_prepend_file",
  "additional_directives",
  "pm_type",
  "pm_max_children",
  "pm_max_requests",
  "allow_url_fopen",
]);

function SectionTitle({ icon: Icon, title }) {
  return (
    <p className="flex items-center gap-2 text-sm font-semibold">
      <Icon className="size-4 text-muted-foreground" />
      {title}
    </p>
  );
}

function NumberField({ form, name, label, directive, explain, disabled, min, max, placeholder }) {
  return (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <Label label={label} name={name} directive={directive} explain={explain} />
          <FormControl>
            <Input
              {...field}
              type="number"
              inputMode="numeric"
              min={min}
              max={max}
              placeholder={placeholder}
              disabled={disabled}
              className="tabular-nums"
            />
          </FormControl>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

function TextField({ form, name, label, directive, explain, disabled, mono = false, placeholder }) {
  return (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <Label label={label} name={name} directive={directive} explain={explain} />
          <FormControl>
            <Input
              {...field}
              spellCheck={false}
              placeholder={placeholder}
              disabled={disabled}
              className={cn(mono && "font-mono text-xs")}
            />
          </FormControl>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

function ToggleRow({ form, name, label, directive, explain, disabled }) {
  return (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem className="flex items-start justify-between gap-4 rounded-lg border px-3 py-2.5">
          {/* flex-1 so the Label's justify-between pushes Reset right, beside the switch. */}
          <div className="min-w-0 flex-1 space-y-1">
            <Label label={label} name={name} directive={directive} explain={explain} />
          </div>
          <FormControl>
            <Switch
              className="mt-0.5"
              checked={field.value}
              onCheckedChange={field.onChange}
              disabled={disabled}
            />
          </FormControl>
          {/* basis-full so it wraps below rather than squeezing the switch. */}
          <FormMessage className="basis-full" />
        </FormItem>
      )}
    />
  );
}

// The API joins with `:`, the textarea gives newlines; a paste may use either.
function splitPaths(value) {
  return (value ?? "")
    .split(/[:\n,]+/)
    .map((path) => path.trim())
    .filter(Boolean);
}

// Paths the backend always prepends are fixed chips: deleting one from the textarea would return on save.
function OpenBasedir({ form, php, disabled }) {
  const t = useTranslations("applications.php");
  const tb = useTranslations("applications.php.basedir");

  const enabled = useWatch({ control: form.control, name: "open_basedir_enabled" });

  const live = php.open_basedir_live ?? null;
  const effective = php.open_basedir_effective ?? null;
  const recommended = splitPaths(php.open_basedir_recommended);

  // Null means the live value could not be read; never show it as "off".
  const unknown = enabled && live === null;

  // Compared as sets: path order in the pool file does not matter.
  const sameAsSaved = (() => {
    if (live === null || effective === null) return false;
    const [a, b] = [splitPaths(live), splitPaths(effective)];
    return a.length === b.length && [...a].sort().join(":") === [...b].sort().join(":");
  })();

  // Strictly "live differs from saved", deliberately not tied to `php.managed`. Never while `live` is unknown.
  const disagrees = enabled && !unknown && !sameAsSaved;

  return (
    <div className="space-y-3 rounded-lg border p-3">
      <FormField
        control={form.control}
        name="open_basedir_enabled"
        render={({ field }) => (
          <FormItem className="flex items-start justify-between gap-4">
            <div className="space-y-1">
              <Label
                label={t("fields.openBasedir")}
                name="open_basedir_enabled"
                directive="open_basedir"
              />
              <p className="text-xs text-muted-foreground">
                {enabled ? t("hints.openBasedir") : tb("offHint")}
              </p>
            </div>
            <FormControl>
              <Switch
                className="mt-0.5"
                checked={field.value}
                onCheckedChange={field.onChange}
                disabled={disabled}
              />
            </FormControl>
          </FormItem>
        )}
      />

      {disagrees ? (
        <div className="space-y-1.5 rounded-lg border border-warning/40 bg-warning/10 p-3">
          <p className="flex items-start gap-2 text-sm">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
            <span>{tb("disagrees")}</span>
          </p>
          <p className="text-xs text-muted-foreground">{tb("disagreesWhy")}</p>
          {live ? <PathList paths={splitPaths(live)} label={tb("liveLabel")} /> : null}
        </div>
      ) : null}

      {enabled ? (
        <div className="space-y-3">
          {unknown ? (
            <p className="rounded-lg border border-dashed px-3 py-2 text-xs text-muted-foreground">
              {tb("unknown")}
            </p>
          ) : null}

          <PathList paths={recommended} label={tb("alwaysLabel")} hint={tb("alwaysHint")} />

          <FormField
            control={form.control}
            name="open_basedir_paths"
            render={({ field }) => (
              <FormItem>
                <Label
                  label={tb("extraLabel")}
                  name="open_basedir_paths"
                  explain={tb("extraHint")}
                />
                <FormControl>
                  <Textarea
                    {...field}
                    rows={3}
                    spellCheck={false}
                    placeholder={tb("extraPlaceholder")}
                    className="font-mono text-xs"
                    disabled={disabled}
                  />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
        </div>
      ) : (
        // Preview of the paths that would be allowed before switching it on.
        <Collapsible>
          <CollapsibleTrigger className="text-xs text-muted-foreground underline-offset-2 hover:underline">
            {tb("previewTrigger")}
          </CollapsibleTrigger>
          <CollapsibleContent className="pt-2">
            <PathList paths={recommended} label={tb("previewLabel")} />
          </CollapsibleContent>
        </Collapsible>
      )}
    </div>
  );
}

function PathList({ paths, label, hint }) {
  if (paths.length === 0) return null;
  return (
    <div className="space-y-1.5">
      <p className="text-xs font-medium">{label}</p>
      <ul className="flex flex-wrap gap-1.5">
        {paths.map((path) => (
          <li
            key={path}
            className="rounded-md bg-muted px-2 py-0.5 font-mono text-xs break-all text-muted-foreground"
          >
            {path}
          </li>
        ))}
      </ul>
      {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
    </div>
  );
}

// The form value stays the comma-joined string the API takes.
function BlockedFunctions({ form, php, disabled }) {
  const t = useTranslations("applications.php");
  const tb = useTranslations("applications.php.blocked");
  const [draft, setDraft] = useState("");
  // Invalid names from the last Add, reported instead of added.
  const [refused, setRefused] = useState([]);

  const value = useWatch({ control: form.control, name: "disable_functions" }) ?? "";
  const names = value
    .split(",")
    .map((name) => name.trim())
    .filter(Boolean);

  const write = (next) =>
    form.setValue("disable_functions", next.join(","), {
      shouldDirty: true,
      shouldValidate: true,
    });

  // Presets come from the API, safest first, localised. Older backends send
  // only the flat suggested string, treated as a one-entry list.
  const presets = php.disable_functions_presets?.length
    ? php.disable_functions_presets
    : php.suggested_disable_functions
      ? [
          {
            key: "safe",
            title: t("useSuggested"),
            description: "",
            functions: php.suggested_disable_functions,
          },
        ]
      : [];
  // Order and whitespace are ignored when matching a preset.
  const asSet = (list) =>
    (list ?? "")
      .split(",")
      .map((name) => name.trim())
      .filter(Boolean)
      .sort()
      .join(",");
  const activePreset = presets.find(
    (preset) => asSet(preset.functions) === asSet(value),
  );

  function add() {
    // Accepts a pasted list; the Set drops duplicates within the paste.
    const typed = [...new Set(draft.split(/[,\s]+/).map((name) => name.trim()).filter(Boolean))];
    const valid = typed.filter((name) => /^[A-Za-z0-9_]+$/.test(name));
    const added = valid.filter((name) => !names.includes(name));

    if (added.length) write([...names, ...added]);
    setRefused(typed.filter((name) => !valid.includes(name)));
    setDraft("");
  }

  return (
    <FormField
      control={form.control}
      name="disable_functions"
      render={() => (
        <FormItem>
          <Label
            label={t("fields.disableFunctions")}
            explain={t("hints.disableFunctions")}
            name="disable_functions"
            directive="disable_functions"
          />
          <p className="text-xs text-muted-foreground">{tb("description")}</p>

          {names.length ? (
            <div className="flex flex-wrap gap-1.5 rounded-lg border p-2.5">
              {names.map((name) => (
                <span
                  key={name}
                  className="inline-flex items-center gap-1 rounded-md border bg-muted/50 py-0.5 pl-2 pr-1 font-mono text-xs"
                >
                  {name}
                  <button
                    type="button"
                    disabled={disabled}
                    aria-label={tb("remove", { name })}
                    onClick={() => write(names.filter((other) => other !== name))}
                    className="rounded-sm p-0.5 text-muted-foreground transition-colors hover:bg-background hover:text-foreground disabled:pointer-events-none disabled:opacity-50"
                  >
                    <X className="size-3" />
                  </button>
                </span>
              ))}
            </div>
          ) : (
            <p className="rounded-lg border border-dashed p-2.5 text-xs text-muted-foreground">
              {tb("empty")}
            </p>
          )}

          <div className="flex flex-wrap items-center gap-2">
            <Input
              value={draft}
              disabled={disabled}
              spellCheck={false}
              placeholder={tb("addPlaceholder")}
              className="h-8 w-64 font-mono text-xs"
              onChange={(event) => setDraft(event.target.value)}
              onKeyDown={(event) => {
                if (event.key === "Enter") {
                  event.preventDefault();
                  add();
                }
              }}
            />
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={disabled || !draft.trim()}
              onClick={add}
            >
              {tb("add")}
            </Button>

            <p className="ml-auto text-xs text-muted-foreground tabular-nums">
              {tb("count", { count: names.length })}
            </p>
          </div>

          {refused.length ? (
            <p role="alert" className="text-sm text-destructive">
              {tb("invalidName", { names: refused.join(", ") })}
            </p>
          ) : null}

          {/* Same pattern as the FPM presets: description shown as text, not a `title`. */}
          {presets.length ? (
            <div className="self-start">
              <Label label={tb("presetsLabel")} />
              <div className="mt-1.5 flex flex-wrap gap-2">
                {presets.map((preset) => (
                  <Button
                    key={preset.key}
                    type="button"
                    variant={activePreset?.key === preset.key ? "default" : "outline"}
                    size="sm"
                    disabled={disabled}
                    onClick={() =>
                      write(
                        preset.functions
                          .split(",")
                          .map((name) => name.trim())
                          .filter(Boolean),
                      )
                    }
                  >
                    <ShieldCheck className="size-3.5" />
                    {preset.title}
                  </Button>
                ))}
              </div>
              <p className="mt-1.5 min-h-4 text-xs text-muted-foreground">
                {activePreset?.description || tb("presetsCustom")}
              </p>
            </div>
          ) : null}

          <FormMessage />
        </FormItem>
      )}
    />
  );
}
