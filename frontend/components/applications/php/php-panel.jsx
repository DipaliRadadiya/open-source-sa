"use client";

import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  Cpu,
  FileUp,
  Info,
  Loader2,
  Lock,
  MemoryStick,
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
  unisolateApplicationPhp,
  updateApplicationPhp,
} from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { Combobox } from "@/components/ui/combobox";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Switch } from "@/components/ui/switch";
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
  security: ["open_basedir_enabled", "allow_url_fopen", "disable_functions"],
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
  const [confirmPutBack, setConfirmPutBack] = useState(false);

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

  async function putBack() {
    setBusy(true);
    try {
      await unisolateApplicationPhp(appId);
      setConfirmPutBack(false);
      toast.success(t("isolation.putBack"));
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("isolation.failed")));
    } finally {
      setBusy(false);
    }
  }

  return (
    <div className="max-w-4xl space-y-4">
      <IsolationCard
        php={php}
        canManage={canManage}
        busy={busy}
        onIsolate={isolate}
        onPutBack={() => setConfirmPutBack(true)}
      />

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
        />
      ) : (
        /** Shared mode — clean locked state */
        <SharedPhpState php={php} canManage={canManage} busy={busy} onIsolate={isolate} />
      )}

      <ConfirmDialog
        open={confirmPutBack}
        onOpenChange={setConfirmPutBack}
        icon={TriangleAlert}
        tone="warning"
        title={t("isolation.putBackTitle")}
        description={t("isolation.putBackBody")}
        confirmLabel={t("isolation.putBackConfirm")}
        pending={busy}
        onConfirm={putBack}
      />
    </div>
  );
}

// ─── Shared PHP mode ────────────────────────────────────────────────────────

function SharedPhpState({ php, canManage, busy, onIsolate }) {
  const t = useTranslations("applications.php");
  const tShared = useTranslations("applications.php.shared");
  const tIsolation = useTranslations("applications.php.isolation");
  const settings = php.settings;

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
              icon={Cpu}
              label={tShared("version")}
              value={php.php_version ? `PHP ${php.php_version}` : "—"}
            />
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

function DedicatedPhpPanel({ appId, php, timezones, canManage, saving, setSaving }) {
  const t = useTranslations("applications.php");
  const router = useRouter();
  const [tab, setTab] = useState("basic");
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
    try {
      await updateApplicationPhp(appId, values);
      toast.success(t("saved"));
      form.reset(values);
      router.refresh();
    } catch (error) {
      if (error.response?.data?.errors) {
        handleValidationError(error, form);
      } else {
        toast.error(apiMessage(error, t("saveFailed")));
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(save)} className="space-y-3">
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
              <TabsList className="!h-auto w-fit flex-wrap gap-1 p-1">
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
            </div>

            <TabsContent
              value="basic"
              forceMount
              hidden={tab !== "basic"}
              className="space-y-5 px-5 py-5"
            >
              <SectionTitle icon={Cpu} title={t("sections.runtime")} />

              <div className="grid gap-4 sm:grid-cols-2">
                <Stack label={t("fields.version")} directive="php_version">
                  <ValueSelect
                    form={form}
                    name="php_version"
                    disabled={!canManage || saving}
                    options={php.available_versions}
                    render={(value) => t("versionLabel", { version: value })}
                  />
                </Stack>

                <Stack label={t("fields.memory")} directive="memory_limit">
                  <ValueSelect
                    form={form}
                    name="memory_limit"
                    disabled={saving}
                    options={MEMORY_SIZES}
                    customLabel={t("custom")}
                  />
                </Stack>

                <Stack label={t("fields.children")} directive="pm.max_children">
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

                <Stack label={t("fields.executionTime")} directive="max_execution_time">
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

                <Stack label={t("fields.inputVars")} directive="max_input_vars">
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
              <div className="grid gap-3 sm:grid-cols-2">
                <ToggleRow
                  form={form}
                  name="open_basedir_enabled"
                  label={t("fields.openBasedir")}
                  directive="open_basedir"
                  hint={t("hints.openBasedir")}
                  disabled={saving}
                />
                <ToggleRow
                  form={form}
                  name="allow_url_fopen"
                  label={t("fields.allowUrlFopen")}
                  directive="allow_url_fopen"
                  hint={t("hints.allowUrlFopen")}
                  disabled={saving}
                />
              </div>

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
                      <Label label={t("fields.timezone")} />
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
                    <Label label={t("fields.directives")} />
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

function IsolationCard({ php, canManage, busy, onIsolate, onPutBack }) {
  const t = useTranslations("applications.php.isolation");

  // Nothing can be done about this one — no action, no decision, no way to
  // change it from here. There is no card to show, and the explanation lives
  // inside the settings card it applies to (see SharedPhpState).
  if (!php.isolation_supported) return null;

  return (
    <Card className="gap-0 overflow-hidden border-blue-200 bg-blue-50/60 py-0 shadow-sm dark:border-blue-800 dark:bg-blue-950/20">
      <CardContent className="grid gap-4 px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center">
        <div className="flex items-start gap-3">
          {/* The chip is back. A bare 16px icon against a wide tinted card left
              the row looking like a toolbar rather than a header. */}
          <div className="flex size-9 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900">
            <Lock className="size-4 text-blue-600 dark:text-blue-400" />
          </div>
          <div className="min-w-0 space-y-1.5">
            <p className="flex flex-wrap items-center gap-2 font-semibold">
              {php.isolated ? t("onTitle") : t("offTitle")}
              <Badge
                variant="outline"
                className="border-blue-300 bg-blue-100/60 text-xs font-normal text-blue-700 dark:border-blue-700 dark:bg-blue-900/60 dark:text-blue-300"
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

        {canManage ? (
          <Button
            type="button"
            variant={php.isolated ? "outline" : "default"}
            onClick={php.isolated ? onPutBack : onIsolate}
            disabled={busy}
            className="justify-self-start sm:justify-self-end"
          >
            {busy ? <Loader2 className="size-4 animate-spin" /> : null}
            {php.isolated ? t("putBackAction") : t("isolateAction")}
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

function Stack({ label, directive, error, children }) {
  return (
    <FormItem>
      <Label label={label} />
      {children}
      <Directive name={directive} />
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </FormItem>
  );
}

function Label({ label }) {
  return <FormLabel>{label}</FormLabel>;
}

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
          <Label label={label} />
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
          <Label label={label} />
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
            <Label label={label} />
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
          <Label label={t("fields.disableFunctions")} />
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

            {php.suggested_disable_functions && value !== php.suggested_disable_functions ? (
              <Button
                type="button"
                variant="ghost"
                size="sm"
                disabled={disabled}
                onClick={() =>
                  form.setValue("disable_functions", php.suggested_disable_functions, {
                    shouldDirty: true,
                    shouldValidate: true,
                  })
                }
              >
                <ShieldCheck className="size-3.5" />
                {t("useSuggested")}
              </Button>
            ) : (
              <p className="text-xs text-muted-foreground">{tb("matches")}</p>
            )}

            <p className="ml-auto text-xs text-muted-foreground tabular-nums">
              {tb("count", { count: names.length })}
            </p>
          </div>

          <FormMessage />
        </FormItem>
      )}
    />
  );
}
