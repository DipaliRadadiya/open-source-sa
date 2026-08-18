"use client";

import { createContext, useContext, useState } from "react";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
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
import { budgetWith, phpSettingsFormSchema, phpSizeToBytes } from "@/lib/schemas/php-settings";
import {
  isolateApplicationPhp,
  resetApplicationPhpFields,
  updateApplicationPhp,
} from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Badge } from "@/components/ui/badge";
import { ScrollFade } from "@/components/ui/scroll-fade";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { Combobox } from "@/components/ui/combobox";
import { Input } from "@/components/ui/input";
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
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

/** The sizes people actually pick, so most visits are a click and not typing. */
const UPLOAD_SIZES = ["8M", "32M", "64M", "128M", "256M", "512M"];
const MEMORY_SIZES = ["128M", "256M", "512M", "1G"];
const EXECUTION_TIMES = [30, 60, 120, 300];
const INPUT_VARS = [1000, 3000, 5000, 10000];
// 10 is what a fresh pool actually gets, so it belongs on the list — without
// it every unconfigured site opens in Custom.
const WORKERS = [2, 4, 6, 8, 10, 12];

const TABS = [
  { key: "basic", icon: Cpu },
  { key: "security", icon: ShieldCheck },
  { key: "advanced", icon: Settings2 },
];

/**
 * Which tab owns which field, so a change made behind a hidden tab can be
 * marked there. Two of these cross a tab boundary on purpose: a worker preset
 * also writes `pm_type`, and raising the upload limit also raises
 * `post_max_size` — both live on Advanced, and the marker says so rather than
 * letting the change happen invisibly.
 */
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

/**
 * One site's PHP.
 *
 * Every panel surveyed (Plesk, CloudPanel, RunCloud, cPanel) labels these
 * fields with the raw php.ini directive and groups them the way the php.ini
 * manual does. Nobody opens this screen wanting to configure PHP — they open it
 * because an upload failed, an import timed out, or a page builder dropped half
 * a form. So the label is the plain thing, the directive is secondary, and the
 * grouping follows the symptom.
 *
 * The first cut of that was still a document: label, box, explanatory sentence,
 * eight times down the page. Values are picked here, not typed — nobody wants
 * to work out that 64M is the string to write — the explanation is one line per
 * group rather than one per field, and the header keeps a live count of what
 * the site is actually set to.
 */
export function PhpPanel({ appId, php, timezones = [], canManage }) {
  const t = useTranslations("applications.php");
  const router = useRouter();
  const [saving, setSaving] = useState(false);
  const [busy, setBusy] = useState(false);

  const settings = php.settings;

  async function isolate() {
    setBusy(true);
    try {
      await isolateApplicationPhp(appId);
      toast.success(t("isolation.isolated"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("isolation.failed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <div className="max-w-4xl space-y-4">
        <IsolationCard php={php} canManage={canManage} busy={busy} onIsolate={isolate} />
  
        {/* Said before they press save, not after their work has gone. */}
        {php.isolated && !php.managed ? (
          <p className="flex items-start gap-2.5 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
            <span>{t("unmanaged")}</span>
          </p>
        ) : null}
  
        {php.isolated ? (
          /** Dedicated mode — full editable form */
          <DedicatedPhpPanel
            appId={appId}
            php={php}
            timezones={timezones}
            canManage={canManage}
            saving={saving}
            setSaving={setSaving}
            onIsolate={isolate}
          />
        ) : (
          /** Shared mode — clean locked state */
          <SharedPhpState php={php} canManage={canManage} busy={busy} onIsolate={isolate} />
        )}
      </div>
    </DisabledReasonProvider>
  );
}

// ─── Shared PHP mode ────────────────────────────────────────────────────────

function SharedPhpState({ php, canManage, busy, onIsolate }) {
  const t = useTranslations("applications.php");
  const tShared = useTranslations("applications.php.shared");
  const tIsolation = useTranslations("applications.php.isolation");
  const router = useRouter();
  const settings = php.settings;

  // The version is the one thing a pool-less site CAN still change: it lives in
  // the vhost, not the pool, and the API strips it before refusing the rest.
  // This screen offered it nowhere, so those sites were stuck on whatever
  // version they were created with.
  const [version, setVersion] = useState(php.php_version ?? "");
  const [savingVersion, setSavingVersion] = useState(false);
  const versions = php.available_versions ?? [];
  const versionChanged = version !== (php.php_version ?? "");

  async function saveVersion() {
    setSavingVersion(true);
    try {
      // Only the version. Sending the settings alongside it earns a 422 for the
      // whole request — they need a pool file and there isn't one.
      await updateApplicationPhp(php.application_id, { php_version: version });
      toast.success(t("saved"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("saveFailed")));
    } finally {
      setSavingVersion(false);
    }
  }

  return (
    <Card className="overflow-hidden shadow-sm">
      <CardContent className="p-0">
        {/* The "no per-site pools here" note belongs to these settings, so it
            sits inside the card with them. Floating above it, it read as an
            unrelated page-level banner. */}
        {!php.isolation_supported ? (
          <p className="flex items-start gap-2.5 border-b px-5 py-3 text-sm">
            <Info className="mt-0.5 size-4 shrink-0 text-muted-foreground" />
            <span>
              <span className="font-medium">{tIsolation("unsupportedTitle")}</span>{" "}
              <span className="text-muted-foreground">{tIsolation("unsupportedBody")}</span>
            </span>
          </p>
        ) : null}

        {/* Current values strip. The bottom border belongs to the block below
            it — without the preview there is nothing to divide, and the rule
            left an empty band under the card. */}
        <div className={cn("bg-muted/20 px-5 py-4", php.isolation_supported && "border-b")}>
          <p className="mb-3 text-sm font-medium text-muted-foreground">
            {tShared("currentLabel")}
          </p>
          {/* Same status line as the dedicated panel — one screen should not
              show the same five facts two different ways. */}
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

        {/* Promoted out of the read-only strip above, because unlike every
            other value there this one is still changeable without a pool. */}
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
            <p className="text-xs text-muted-foreground">{tShared("versionHint")}</p>
          </div>
          {canManage ? (
            <Button type="button" onClick={saveVersion} disabled={!versionChanged || savingVersion}>
              {savingVersion ? <Loader2 className="size-4 animate-spin" /> : null}
              {t("saveAction")}
            </Button>
          ) : null}
        </div>

        {/* Preview of what dedicated PHP unlocks — withheld where the web
            server has no per-site pools, since it can never be unlocked. */}
        {php.isolation_supported ? (
          <div className="border-t px-5 py-4">
            <p className="mb-3 text-xs font-semibold uppercase tracking-wide text-muted-foreground">
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

function DedicatedPhpPanel({ appId, php, timezones, canManage, saving, setSaving, onIsolate }) {
  const t = useTranslations("applications.php");
  const router = useRouter();
  const [tab, setTab] = useState("basic");
  // The API's own sentence, kept when it refuses a save because the pool file
  // is gone. Null the rest of the time.
  const [needsPool, setNeedsPool] = useState(null);
  const settings = php.settings;

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
    resolver: zodResolver(phpSettingsFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  // Without this a sidebar click throws the edit away silently.
  useWatchUnsaved("app-php-settings", form.formState.isDirty);

  const version = useWatch({ control: form.control, name: "php_version" });
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

  const { dirtyFields } = form.formState;
  const dirtyTabs = new Set(
    TABS.map(({ key }) => key).filter((key) => TAB_FIELDS[key].some((field) => dirtyFields[field])),
  );

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
    setNeedsPool(null);
    try {
      await updateApplicationPhp(appId, values);
      toast.success(t("saved"));
      form.reset(values);
      router.refresh();
    } catch (error) {
      const errors = error.response?.data?.errors;
      // `settings` is not a field on this form, so setError would file it
      // against nothing and the save would fail in silence. It means the pool
      // went away underneath us — every limit here is written by that file, so
      // the answer is not a red input, it is restoring the pool.
      if (errors?.settings) {
        setNeedsPool(errors.settings[0]);
      } else if (errors) {
        handleValidationError(error, form);
      } else {
        toast.error(apiMessage(error, t("saveFailed")));
      }
    } finally {
      setSaving(false);
    }
  }

  /**
   * A cleared field takes the value the response came back with — the default
   * it now inherits, which the form had no way of knowing before asking.
   *
   * Only the cleared fields are written; anything else the user is midway
   * through editing keeps its value AND its dirty state, so a Reset in one
   * corner of the form never quietly discards work in another.
   */
  function onReset(fields, next) {
    const settings = next?.settings;
    if (settings) {
      for (const field of fields) {
        form.setValue(field, settings[field] ?? "", { shouldDirty: false });
      }
    }
    // The `overridden` map lives on the server-rendered prop, so the buttons
    // only disappear once this lands.
    router.refresh();
  }

  return (
    <OverrideContext.Provider
      value={{ appId, overridden: php.overridden ?? {}, disabled: !canManage || saving, onReset }}
    >
    <Form {...form}>
      <form onSubmit={form.handleSubmit(save)} className="space-y-3">
        {/* The pool vanished between loading this screen and pressing save, so
            nothing here can be applied. Persistent rather than a toast: it is
            not a message about the click, it is the state the site is in until
            someone fixes it. */}
        {needsPool ? (
          <div className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3">
            <p className="flex min-w-0 items-start gap-2.5 text-sm">
              <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
              <span>{needsPool}</span>
            </p>
            {canManage && onIsolate ? (
              <Button type="button" size="sm" onClick={onIsolate} disabled={saving}>
                {t("isolation.isolateAction")}
              </Button>
            ) : null}
          </div>
        ) : null}

        {/* Its own strip above the form, not the form's first row: it is what
            the site IS, not something you edit. It still tracks the fields,
            so an unsaved change is visible as the value it would become. */}
        <div className="flex flex-wrap items-center gap-x-5 gap-y-2 rounded-lg border bg-muted/30 px-4 py-2.5 text-xs">
          <Stat icon={Cpu} label={t("summary.version")} value={version || "—"} />
          <Stat icon={MemoryStick} label={t("summary.memory")} value={memoryLimit} />
          <Stat icon={FileUp} label={t("summary.upload")} value={upload} />
          <Stat icon={Timer} label={t("summary.time")} value={t("seconds", { count: execution })} />
          <Stat icon={User} label={t("summary.runsAs")} value={php.runs_as ?? "—"} />
        </div>

        <Card className="gap-0 overflow-hidden py-0 shadow-sm">
          {/* Three tabs, not one long page.
              Everything a normal site ever needs is on Basic; the FPM knobs and
              the raw directive box are a deliberate second trip. All three
              panels stay mounted — an unmounting tab throws away a half-typed
              value, and a form that loses your work when you look at something
              else is the same bug as a dialog that closes itself. */}
          <Tabs value={tab} onValueChange={setTab} className="gap-0">
            <div className="border-b px-5 py-3">
              {/* Wraps rather than clips: at 390px the third trigger ran off
                  the card edge with no scroll affordance to say so. */}
              {/* Scrolls rather than wraps, same as the Settings tab bar: a bar that
                  reflows to two rows stops reading as one control. ScrollFade is what
                  says there is more to the side. */}
              <ScrollFade className="-mx-1 px-1 pb-1">
                <TabsList className="!h-auto w-fit gap-1 p-1">
                  {TABS.map(({ key, icon: Icon }) => (
                    <TabsTrigger key={key} value={key} className="gap-2 px-3 py-1.5">
                      <Icon className="size-4" />
                      {t(`tabs.${key}`)}
                      {/* A change hidden behind another tab still has to be
                          findable — otherwise "Not saved yet" points at nothing
                          on screen. */}
                      {dirtyTabs.has(key) ? (
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
                <Stack label={t("fields.version")} name="php_version" directive="php_version">
                  <ValueSelect
                    form={form}
                    name="php_version"
                    disabled={!canManage || saving}
                    options={php.available_versions}
                    render={(value) => t("versionLabel", { version: value })}
                  />
                </Stack>

                <Stack label={t("fields.memory")} name="memory_limit" directive="memory_limit">
                  <ValueSelect
                    form={form}
                    name="memory_limit"
                    disabled={saving}
                    options={MEMORY_SIZES}
                    customLabel={t("custom")}
                  />
                </Stack>

                <Stack label={t("fields.children")} name="pm_max_children" directive="pm.max_children">
                  <ValueSelect
                    form={form}
                    name="pm_max_children"
                    disabled={saving}
                    options={WORKERS}
                    numeric
                    customLabel={t("custom")}
                  />
                </Stack>

                {php.presets.length > 0 ? (
                  <div className="self-start">
                    <Label label={t("presetsLabel")} />
                    <div className="mt-1.5 flex flex-wrap gap-2">
                      {php.presets.map((preset) => (
                        <Button
                          key={preset.key}
                          type="button"
                          variant={activePreset?.key === preset.key ? "default" : "outline"}
                          size="sm"
                          disabled={saving}
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
                    {/* The description used to live in a `title` attribute, so
                        on a phone there was no way to learn what "Busy" meant.
                        It is now read out for whichever preset is selected. */}
                    <p className="mt-1.5 min-h-4 text-xs text-muted-foreground">
                      {activePreset?.description ?? t("presetsCustom")}
                    </p>
                  </div>
                ) : null}
              </div>

              {/* Directly under the two numbers it multiplies. */}
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
                >
                  <ValueSelect
                    form={form}
                    name="upload_max_filesize"
                    disabled={saving}
                    options={UPLOAD_SIZES}
                    onPick={setUpload}
                    customLabel={t("custom")}
                  />
                </Stack>

                <Stack label={t("fields.executionTime")} name="max_execution_time" directive="max_execution_time">
                  <ValueSelect
                    form={form}
                    name="max_execution_time"
                    disabled={saving}
                    options={EXECUTION_TIMES}
                    render={(value) => t("seconds", { count: value })}
                    numeric
                    customLabel={t("custom")}
                  />
                </Stack>

                <Stack label={t("fields.inputVars")} name="max_input_vars" directive="max_input_vars">
                  <ValueSelect
                    form={form}
                    name="max_input_vars"
                    disabled={saving}
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
              {/* Promoted out of the toggle pair: it is the only setting here
                  that has a value, a state the server may disagree with, and a
                  state we cannot read at all. A switch could say none of that. */}
              <OpenBasedir form={form} php={php} disabled={saving} />

              <ToggleRow
                form={form}
                name="allow_url_fopen"
                label={t("fields.allowUrlFopen")}
                directive="allow_url_fopen"
                hint={t("hints.allowUrlFopen")}
                disabled={saving}
              />

              <BlockedFunctions form={form} php={php} disabled={saving} />
            </TabsContent>

            <TabsContent
              value="advanced"
              forceMount
              hidden={tab !== "advanced"}
              className="space-y-5 bg-muted/20 px-5 py-5"
            >
              {/* Said before the fields, not after someone has changed one. */}
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
                      <Label label={t("fields.pmType")} />
                      <FormControl>
                        <Combobox
                          options={["ondemand", "dynamic", "static"].map((value) => ({
                            value,
                            label: t(`pmTypes.${value}`),
                          }))}
                          value={field.value}
                          onChange={field.onChange}
                          disabled={saving}
                        />
                      </FormControl>
                      <Directive name="pm" />
                      <FormDescription>{t("hints.pmType")}</FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <NumberField
                  form={form}
                  name="pm_max_requests"
                  label={t("fields.maxRequests")}
                  directive="pm.max_requests"
                  hint={t("hints.maxRequests")}
                  disabled={saving}
                  min={0}
                  max={100000}
                />
                <NumberField
                  form={form}
                  name="max_input_time"
                  label={t("fields.inputTime")}
                  directive="max_input_time"
                  hint={t("hints.inputTime")}
                  disabled={saving}
                  min={-1}
                  max={3600}
                />
                <NumberField
                  form={form}
                  name="session_gc_maxlifetime"
                  label={t("fields.sessionLifetime")}
                  directive="session.gc_maxlifetime"
                  hint={t("hints.sessionLifetime")}
                  disabled={saving}
                  min={60}
                  max={604800}
                />

                <FormField
                  control={form.control}
                  name="php_timezone"
                  render={({ field }) => (
                    <FormItem>
                      <Label label={t("fields.timezone")} name="php_timezone" />
                      <FormControl>
                        <Combobox
                          options={timezones.map((zone) => ({ value: zone, label: zone }))}
                          value={field.value}
                          onChange={field.onChange}
                          disabled={saving}
                          placeholder={t("fields.timezonePlaceholder")}
                        />
                      </FormControl>
                      <Directive name="date.timezone" />
                      <FormDescription>{t("hints.timezone")}</FormDescription>
                      <FormMessage />
                    </FormItem>
                  )}
                />

                <TextField
                  form={form}
                  name="auto_prepend_file"
                  label={t("fields.autoPrepend")}
                  directive="auto_prepend_file"
                  hint={t("hints.autoPrepend")}
                  disabled={saving}
                  mono
                />

                {/* Kept, even though the upload control writes it: a pool that
                    was tuned by hand may hold a value the picker cannot
                    express, and hiding the field would silently overwrite it. */}
                <TextField
                  form={form}
                  name="post_max_size"
                  label={t("fields.post")}
                  directive="post_max_size"
                  hint={t("hints.post")}
                  disabled={saving}
                  mono
                />
              </div>

              <FormField
                control={form.control}
                name="additional_directives"
                render={({ field }) => (
                  <FormItem className="border-t pt-5">
                    <Label label={t("fields.directives")} name="additional_directives" />
                    <FormControl>
                      <Textarea
                        {...field}
                        rows={4}
                        spellCheck={false}
                        disabled={saving}
                        className="font-mono text-xs"
                        placeholder={t("fields.directivesPlaceholder")}
                      />
                    </FormControl>
                    <Directive name="php_admin_value" />
                    <FormDescription>{t("hints.directives")}</FormDescription>
                    <FormMessage />
                  </FormItem>
                )}
              />
            </TabsContent>
          </Tabs>

          <CardSaveFooter
            submit
            saving={saving}
            dirty={form.formState.isDirty}
            saveReason={
              !canManage ? t("noPermission") : !form.formState.isDirty ? t("nothingToSave") : null
            }
            onDiscard={() => form.reset(defaults)}
            saveLabel={t("saveAction")}
            note={t("saveNote")}
            showReason
            quietWhenClean
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
}) {
  const value = useWatch({ control: form.control, name });
  const inList = options.some((option) => String(option) === String(value));
  const [custom, setCustom] = useState(!inList);

  return (
    <div className="space-y-1.5">
      <Select
        value={custom ? CUSTOM : String(value ?? "")}
        disabled={disabled}
        onValueChange={(next) => {
          if (next === CUSTOM) {
            setCustom(true);
            return;
          }
          setCustom(false);
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
                type={numeric ? "number" : "text"}
                inputMode={numeric ? "numeric" : undefined}
                disabled={disabled}
                className="font-mono tabular-nums"
              />
            </FormControl>
          )}
        />
      ) : null}
    </div>
  );
}

/**
 * One fact in the status line.
 *
 * These were cells: five bordered boxes with uppercase headings, which read as
 * the header row of a table nobody had written. They are a sentence about the
 * site, so they are set as one — label and value on the same line, separated
 * by a dot from the next.
 */
function Stat({ icon: Icon, label, value }) {
  return (
    <span className="flex items-center gap-1.5">
      <Icon className="size-3.5 shrink-0 text-muted-foreground/70" />
      <span className="text-muted-foreground">{label}</span>
      <span className="font-medium tabular-nums text-foreground">{value}</span>
    </span>
  );
}

/**
 * Whether this site has its own PHP pool, and the way to give it one.
 *
 * No longer a switch between two modes. The shared pool runs every site as the
 * web server's account, so one compromised site can read every other site's
 * `.env` — the backend removed the way back (405), and a button offering it
 * would be promising something the API refuses.
 *
 * What is left is a repair: sites created before pools existed still have none,
 * and this converts them.
 */
function IsolationCard({ php, canManage, busy, onIsolate }) {
  const t = useTranslations("applications.php.isolation");

  // Nothing can be done about this one — no action, no decision, no way to
  // change it from here. There is no card to show, and the explanation lives
  // inside the settings card it applies to (see SharedPhpState).
  if (!php.isolation_supported) return null;

  return (
    <Card className="gap-0 overflow-hidden border-blue-200 bg-blue-50/60 py-0 shadow-sm dark:border-blue-800 dark:bg-blue-950/20">
      <CardContent className="grid gap-4 px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center">
        <div className="flex min-w-0 items-start gap-3">
          {/* The chip is back. A bare 16px icon against a wide tinted card left
              the row looking like a toolbar rather than a header. Hidden on the
              narrowest screens, where it costs a quarter of the column. */}
          <div className="hidden size-9 shrink-0 items-center justify-center rounded-full bg-blue-100 sm:flex dark:bg-blue-900">
            <Lock className="size-4 text-blue-600 dark:text-blue-400" />
          </div>
          <div className="min-w-0 space-y-1.5">
            <p className="flex flex-wrap items-center gap-2 font-semibold">
              {php.isolated ? t("onTitle") : t("offTitle")}
              <Badge
                variant="outline"
                // whitespace-normal: this badge carries a sentence with the
                // pool user in it ("Shared PHP · runs as www-data"), and a badge
                // is nowrap and shrink-0 by default — 234px that could not fit a
                // 244px card, so it pushed the whole row past the edge.
                className="h-auto border-blue-300 bg-blue-100/60 py-0.5 text-xs font-normal whitespace-normal text-blue-700 dark:border-blue-700 dark:bg-blue-900/60 dark:text-blue-300"
              >
                {php.isolated
                  ? t("dedicatedBadge")
                  : t("sharedBadge", { user: php.runs_as ?? "www-data" })}
              </Badge>
            </p>
            <p className="text-sm text-muted-foreground">
              {php.isolated ? t("onBody") : t("offBody")}
            </p>
            {!php.isolated ? (
              <p className="text-xs text-muted-foreground">{t("isolateHelper")}</p>
            ) : null}
          </div>
        </div>

        {/* Only on a site that still lacks a pool. An isolated site has nothing
            to do here — the card is a statement of fact, not a control. */}
        {canManage && !php.isolated ? (
          <Button
            type="button"
            onClick={onIsolate}
            disabled={busy}
            // "Give this site dedicated PHP" is 28 characters and a button is
            // whitespace-nowrap, so as a grid item with min-width:auto it set the
            // whole card|s minimum width and pushed the copy flush against the
            // right edge. It wraps instead.
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

/**
 * The sum, written out.
 *
 * It used to read "About 12 GB at full load — 12 workers at once" with "More
 * than this server's 2 GB" at the other end of the line: two halves of one
 * sentence, and the reader had to join them. The multiplication is the whole
 * point of the card, so it is now shown as a multiplication, and when it does
 * not fit the numbers and the way out are one sentence rather than a colour.
 */
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
            limit: limit || "—",
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
              required: formatBytes(budget.thisSite),
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

function Stack({ label, name, directive, error, children }) {
  return (
    <FormItem>
      <Label label={label} name={name} />
      {children}
      <Directive name={directive} />
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </FormItem>
  );
}

/**
 * Everything the per-field Reset needs, so the six field components below do
 * not each grow four props to pass it down.
 */
const OverrideContext = createContext(null);

function Label({ label, name }) {
  return (
    <div className="flex min-h-5 items-center justify-between gap-2">
      <FormLabel>{label}</FormLabel>
      <ResetOverride name={name} />
    </div>
  );
}

/**
 * "This site sets its own value — put it back."
 *
 * Its presence IS the marker: a field with no button is inheriting. The
 * alternative — a "Default" chip on every untouched field — is sixteen grey
 * chips on a form already dense with numbers, and it decorates the common case
 * to describe the rare one.
 *
 * It saves rather than editing the form, because it has to. The API returns
 * effective values only, so an inherited 128M and an override that happens to
 * be 128M are indistinguishable here; the value this field will fall back to is
 * only knowable by asking. The response carries it, so one round trip both
 * clears the override and shows the result.
 */
function ResetOverride({ name }) {
  const context = useContext(OverrideContext);
  const t = useTranslations("applications.php");
  const [busy, setBusy] = useState(false);

  if (!context || !name || !RESETTABLE.has(name)) return null;
  // `fields` is what this control clears — usually itself, but the upload
  // control writes two directives and has to clear both (see RESET_FIELDS).
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

/**
 * Controls that own more than one directive.
 *
 * "Largest upload" writes `upload_max_filesize` AND `post_max_size` together —
 * that pairing is the whole point of the control, since a `post_max_size`
 * smaller than the upload limit is the trap every PHP guide warns about. So its
 * Reset has to clear both; clearing only the one it is named after would leave
 * a stale override behind and break uploads exactly the way the single control
 * exists to prevent.
 */
const RESET_FIELDS = {
  upload_max_filesize: ["upload_max_filesize", "post_max_size"],
};

/**
 * The directives the API will actually accept a null for.
 *
 * `SavePhpSettingsRequest` marks every rule `sometimes`; these are the ones that
 * also carry `nullable`, so null clears the override and the site falls back to
 * the server default. The four pool and fopen directives were added by the
 * backend on 2026-08-13 (they used to 422, so they had no button rather than a
 * broken one — a control that looks available and is not is worse than its
 * absence).
 *
 * `open_basedir_enabled` stays off this list on purpose, and it is not an
 * oversight: it is a plain boolean with a column default of `false`, so it has
 * no override to clear. "Reset" for that one is just switching it off.
 */
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

/**
 * The php.ini name, under the field rather than beside the label.
 *
 * It has to stay visible — someone arriving from a Stack Overflow answer is
 * looking for `upload_max_filesize`, not for "biggest file someone can upload"
 * — but sitting next to every label it made the page read as a config file.
 * Not a tooltip: Radix tooltips do not open on touch, so a phone would never
 * see it.
 */
function SectionTitle({ icon: Icon, title }) {
  return (
    <p className="flex items-center gap-2 text-sm font-semibold">
      <Icon className="size-4 text-muted-foreground" />
      {title}
    </p>
  );
}

function Directive({ name }) {
  if (!name) return null;
  // Pulled up against the control it names — at the FormItem's own gap it
  // floated between two fields and belonged to neither.
  return <span className="-mt-1 font-mono text-[0.7rem] text-muted-foreground">{name}</span>;
}

function NumberField({ form, name, label, directive, hint, disabled, min, max }) {
  return (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <Label label={label} name={name} />
          <FormControl>
            <Input
              {...field}
              type="number"
              inputMode="numeric"
              min={min}
              max={max}
              disabled={disabled}
              className="tabular-nums"
            />
          </FormControl>
          <Directive name={directive} />
          <FormDescription>{hint}</FormDescription>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

function TextField({ form, name, label, directive, hint, disabled, mono = false }) {
  return (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <Label label={label} name={name} />
          <FormControl>
            <Input
              {...field}
              spellCheck={false}
              disabled={disabled}
              className={cn(mono && "font-mono text-xs")}
            />
          </FormControl>
          <Directive name={directive} />
          <FormDescription>{hint}</FormDescription>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

function ToggleRow({ form, name, label, directive, hint, disabled }) {
  return (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem className="flex items-start justify-between gap-4 rounded-lg border px-3 py-2.5">
          <div className="space-y-1">
            <Label label={label} name={name} />
            <Directive name={directive} />
            {hint ? <p className="text-xs text-muted-foreground">{hint}</p> : null}
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
  );
}

// One path per line, however it was stored (the API joins with `:`, the
// textarea gives back newlines, and a paste from a php.ini uses either).
function splitPaths(value) {
  return (value ?? "")
    .split(/[:\n,]+/)
    .map((path) => path.trim())
    .filter(Boolean);
}

/**
 * open_basedir: the fence, what is inside it, and whether the server agrees.
 *
 * The three answers the API gives can all differ, and the difference is the
 * point — see `open_basedir_live` in the schema. A switch alone said none of
 * it: someone could be looking at "on" while PHP enforced something else
 * entirely, or at "off" with no idea what turning it on would cost them.
 *
 * The three paths the backend always prepends are shown as fixed chips rather
 * than seeded into the textarea. Seeding them reads as "yours to edit", and
 * deleting one would simply bring it back on the next save.
 */
function OpenBasedir({ form, php, disabled }) {
  const t = useTranslations("applications.php");
  const tb = useTranslations("applications.php.basedir");

  const enabled = useWatch({ control: form.control, name: "open_basedir_enabled" });

  const live = php.open_basedir_live ?? null;
  const effective = php.open_basedir_effective ?? null;
  const recommended = splitPaths(php.open_basedir_recommended);

  // Distinct from "off" on purpose. Null is "we could not find out", and
  // drawing that as "nothing is restricted" would be a guess, wrong about half
  // the time, about a security control.
  const unknown = enabled && live === null;

  // Compared as sets: the pool file may list the same paths in another order,
  // and re-ordering a colon list changes nothing about what PHP allows.
  const sameAsSaved = (() => {
    if (live === null || effective === null) return false;
    const [a, b] = [splitPaths(live), splitPaths(effective)];
    return a.length === b.length && [...a].sort().join(":") === [...b].sort().join(":");
  })();

  /**
   * Strictly "the live value is not the saved one".
   *
   * Deliberately NOT `|| php.managed === false`: a hand-edited pool may have
   * touched anything, and if its open_basedir still matches ours then this
   * particular setting is fine — saying otherwise would be a false alarm
   * pointing at the wrong line. The panel already carries its own banner for
   * hand-edited pools, which is the right place for that.
   *
   * Only once the setting is on: with it off `effective` is null by definition,
   * so every site would wear a permanent warning. And never while `live` is
   * unknown — not knowing what the server enforces is its own state, not
   * evidence of disagreement.
   */
  const disagrees = enabled && !unknown && !sameAsSaved;

  return (
    <div className="space-y-3 rounded-lg border p-3">
      <FormField
        control={form.control}
        name="open_basedir_enabled"
        render={({ field }) => (
          <FormItem className="flex items-start justify-between gap-4">
            <div className="space-y-1">
              <Label label={t("fields.openBasedir")} name="open_basedir_enabled" />
              <Directive name="open_basedir" />
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

      {/* Above the fields, not below them: this is the answer to "why is my
          site still reaching that folder", and it is worth reading before
          anyone edits a path. */}
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
                <Label label={tb("extraLabel")} name="open_basedir_paths" />
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
                <FormDescription>{tb("extraHint")}</FormDescription>
                <FormMessage />
              </FormItem>
            )}
          />
        </div>
      ) : (
        // What it would cost, before committing to it. Someone deciding whether
        // to switch this on is really asking "will it break my site", and the
        // honest answer is this list.
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

/**
 * The blocked-function list.
 *
 * It was a textarea holding `exec,passthru,shell_exec,…` — the stored value,
 * shown raw. Nobody reads a comma-separated line to check whether `system` is
 * in it, and the one thing people do here is add or remove a single name. So
 * the list is a list, each name removable, with an input to add one. The form
 * value is still the same comma-joined string the API takes.
 */
function BlockedFunctions({ form, php, disabled }) {
  const t = useTranslations("applications.php");
  const tb = useTranslations("applications.php.blocked");
  const [draft, setDraft] = useState("");

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

  // The API sends the starting points, safest first, already localised. An
  // older backend only sends the flat suggested string — treat that as a
  // one-entry list so there is a single code path.
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
  // Order and spacing are noise here — a list is the same list however it was
  // typed, and a preset that quietly stops matching over whitespace would keep
  // claiming the site is unhardened.
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
    // A pasted list is a list — splitting it here saves adding five names one
    // at a time, and drops the duplicates that produces.
    const added = draft
      .split(/[,\s]+/)
      .map((name) => name.trim())
      .filter(Boolean)
      .filter((name) => !names.includes(name));

    if (added.length) write([...names, ...added]);
    setDraft("");
  }

  return (
    <FormField
      control={form.control}
      name="disable_functions"
      render={() => (
        <FormItem>
          <Label label={t("fields.disableFunctions")} name="disable_functions" />
          <Directive name="disable_functions" />
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

          {/* One button per starting point, same shape as the FPM presets
              above: the matching one is filled in, and its description is read
              out underneath rather than hidden in a `title` no phone can
              reach. Rendered from the API, so a third preset needs no change
              here. */}
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
