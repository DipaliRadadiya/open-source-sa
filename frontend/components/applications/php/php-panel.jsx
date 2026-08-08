"use client";

import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  ChevronDown,
  Clock,
  Cpu,
  FileUp,
  Loader2,
  Lock,
  ShieldCheck,
  TriangleAlert,
  Settings2,
  Zap,
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
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
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
  const [showAdvanced, setShowAdvanced] = useState(false);

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
          showAdvanced={showAdvanced}
          setShowAdvanced={setShowAdvanced}
          saving={saving}
          setSaving={setSaving}
        />
      ) : (
        /** Shared mode — clean locked state */
        <SharedPhpState
          php={php}
          canManage={canManage}
          busy={busy}
          onIsolate={isolate}
        />
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
  const settings = php.settings;

  return (
    <Card className="overflow-hidden shadow-sm">
      <CardContent className="p-0">
        {/* Current values strip */}
        <div className="border-b bg-muted/20 px-5 py-4">
          <p className="mb-3 text-sm font-medium text-muted-foreground">
            {tShared("currentLabel")}
          </p>
          <div className="grid grid-cols-2 gap-x-6 gap-y-2 sm:grid-cols-5">
            <SharedStat
              label={tShared("version")}
              value={php.php_version ? `PHP ${php.php_version}` : "—"}
            />
            <SharedStat
              label={tShared("memory")}
              value={settings.memory_limit ?? "—"}
            />
            <SharedStat
              label={tShared("upload")}
              value={settings.upload_max_filesize ?? "—"}
            />
            <SharedStat
              label={tShared("time")}
              value={settings.max_execution_time ? `${settings.max_execution_time}s` : "—"}
            />
            <SharedStat
              label={tShared("runsAs")}
              value={php.runs_as ?? "www-data"}
            />
          </div>
        </div>

        {/* Preview of what dedicated PHP unlocks */}
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
      </CardContent>
    </Card>
  );
}

function SharedStat({ label, value }) {
  return (
    <div>
      <p className="text-[0.7rem] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="truncate font-mono text-sm font-medium tabular-nums">{value}</p>
    </div>
  );
}

// ─── Dedicated PHP mode ──────────────────────────────────────────────────────

function DedicatedPhpPanel({
  appId,
  php,
  timezones,
  canManage,
  showAdvanced,
  setShowAdvanced,
  saving,
  setSaving,
}) {
  const t = useTranslations("applications.php");
  const router = useRouter();
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
  const maxChildren = useWatch({ control: form.control, name: "pm_max_children" });
  const upload = useWatch({ control: form.control, name: "upload_max_filesize" });
  const post = useWatch({ control: form.control, name: "post_max_size" });
  const execution = useWatch({ control: form.control, name: "max_execution_time" });
  const pmType = useWatch({ control: form.control, name: "pm_type" });

  const budget = budgetWith(php.memory, memoryLimit, maxChildren);

  const postTooSmall = phpSizeToBytes(post) < phpSizeToBytes(upload);

  const activePreset = php.presets.find(
    (preset) =>
      preset.pm_type === pmType && Number(preset.pm_max_children) === Number(maxChildren),
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
      <form onSubmit={form.handleSubmit(save)}>
        <Card className="gap-0 overflow-hidden py-0 shadow-sm">
          {/* Live summary strip */}
          <div className="grid grid-cols-2 divide-x divide-y border-b sm:grid-cols-4 sm:divide-y-0">
            <Stat label={t("summary.version")} value={version || "—"} />
            <Stat label={t("summary.memory")} value={memoryLimit} />
            <Stat label={t("summary.upload")} value={upload} />
            <Stat label={t("summary.time")} value={t("seconds", { count: execution })} />
          </div>

          <CardContent className="divide-y p-0">
            {/* PHP runtime */}
            <Group icon={Cpu} title={t("groups.runtime")}>
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
              </div>

              <MemoryBudget budget={budget} workers={maxChildren} />
            </Group>

            {/* Request limits */}
            <Group icon={FileUp} title={t("groups.limits")}>
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
            </Group>

            {/* PHP security */}
            <Group icon={ShieldCheck} title={t("groups.security")}>
              <div className="grid gap-3 sm:grid-cols-2">
                <ToggleRow
                  form={form}
                  name="open_basedir_enabled"
                  label={t("fields.openBasedir")}
                  directive="open_basedir"
                  disabled={saving}
                />
                <ToggleRow
                  form={form}
                  name="allow_url_fopen"
                  label={t("fields.allowUrlFopen")}
                  directive="allow_url_fopen"
                  disabled={saving}
                />
              </div>

              <FormField
                control={form.control}
                name="disable_functions"
                render={({ field }) => (
                  <FormItem>
                    <Label label={t("fields.disableFunctions")} directive="disable_functions" />
                    <FormControl>
                      <Textarea
                        {...field}
                        rows={2}
                        spellCheck={false}
                        disabled={saving}
                        className="font-mono text-xs"
                      />
                    </FormControl>
                    {php.suggested_disable_functions &&
                    field.value !== php.suggested_disable_functions ? (
                      <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        disabled={saving}
                        className="w-fit"
                        onClick={() =>
                          form.setValue("disable_functions", php.suggested_disable_functions, {
                            shouldDirty: true,
                          })
                        }
                      >
                        <ShieldCheck className="size-3.5" />
                        {t("useSuggested")}
                      </Button>
                    ) : null}
                    <FormMessage />
                  </FormItem>
                )}
              />
            </Group>

            {/* Advanced PHP directives */}
            <Collapsible open={showAdvanced} onOpenChange={setShowAdvanced}>
              <CollapsibleTrigger asChild>
                <button
                  type="button"
                  className="flex w-full items-center justify-between px-5 py-4 text-sm font-medium transition-colors hover:bg-muted/40"
                >
                  <span className="flex items-center gap-2">
                    <Settings2 className="size-4 text-muted-foreground" />
                    {t("groups.advanced")}
                  </span>
                  <ChevronDown
                    className={cn("size-4 transition-transform", showAdvanced && "rotate-180")}
                  />
                </button>
              </CollapsibleTrigger>
              <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                <div className="space-y-4 border-t px-5 py-4">
                  <div className="grid gap-4 sm:grid-cols-2">
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

                    <FormField
                      control={form.control}
                      name="pm_type"
                      render={({ field }) => (
                        <FormItem>
                          <Label label={t("fields.pmType")} directive="pm" />
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
                          <FormMessage />
                        </FormItem>
                      )}
                    />

                    {php.presets.length > 0 ? (
                      <div className="sm:col-span-2">
                        <Label label={t("presetsLabel")} />
                        <div className="mt-1.5 flex flex-wrap gap-2">
                          {php.presets.map((preset) => (
                            <Button
                              key={preset.key}
                              type="button"
                              variant={activePreset?.key === preset.key ? "default" : "outline"}
                              size="sm"
                              disabled={saving}
                              title={preset.description ?? undefined}
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
                      </div>
                    ) : null}

                    <TextField
                      form={form}
                      name="post_max_size"
                      label={t("fields.post")}
                      directive="post_max_size"
                      disabled={saving}
                      mono
                    />
                    <NumberField
                      form={form}
                      name="max_input_time"
                      label={t("fields.inputTime")}
                      directive="max_input_time"
                      disabled={saving}
                      min={-1}
                      max={3600}
                    />
                    <NumberField
                      form={form}
                      name="pm_max_requests"
                      label={t("fields.maxRequests")}
                      directive="pm.max_requests"
                      disabled={saving}
                      min={0}
                      max={100000}
                    />
                    <NumberField
                      form={form}
                      name="session_gc_maxlifetime"
                      label={t("fields.sessionLifetime")}
                      directive="session.gc_maxlifetime"
                      disabled={saving}
                      min={60}
                      max={604800}
                    />
                    <FormField
                      control={form.control}
                      name="php_timezone"
                      render={({ field }) => (
                        <FormItem>
                          <Label label={t("fields.timezone")} directive="date.timezone" />
                          <FormControl>
                            <Combobox
                              options={timezones.map((zone) => ({ value: zone, label: zone }))}
                              value={field.value}
                              onChange={field.onChange}
                              disabled={saving}
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
                      label={t("fields.autoPrepend")}
                      directive="auto_prepend_file"
                      disabled={saving}
                      mono
                    />
                  </div>

                  <FormField
                    control={form.control}
                    name="additional_directives"
                    render={({ field }) => (
                      <FormItem>
                        <Label label={t("fields.directives")} directive="php_admin_value" />
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
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>
              </CollapsibleContent>
            </Collapsible>
          </CardContent>

          <CardSaveFooter
            submit
            saving={saving}
            dirty={form.formState.isDirty}
            saveReason={
              !canManage
                ? t("noPermission")
                : !form.formState.isDirty
                  ? t("nothingToSave")
                  : null
            }
            onDiscard={() => form.reset(defaults)}
            saveLabel={t("saveAction")}
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

function Stat({ label, value }) {
  return (
    <div className="px-4 py-2.5">
      <p className="text-[0.7rem] uppercase tracking-wide text-muted-foreground">{label}</p>
      <p className="truncate font-medium tabular-nums">{value}</p>
    </div>
  );
}

function IsolationCard({ php, canManage, busy, onIsolate, onPutBack }) {
  const t = useTranslations("applications.php.isolation");

  if (!php.isolation_supported) {
    return (
      <Card className="gap-0 overflow-hidden py-0 shadow-sm">
        <CardContent className="flex items-start gap-3 px-5 py-4">
          <TriangleAlert className="mt-0.5 size-5 shrink-0 text-warning" />
          <div className="space-y-1">
            <p className="font-medium">{t("unsupportedTitle")}</p>
            <p className="text-sm text-muted-foreground">{t("unsupportedBody")}</p>
          </div>
        </CardContent>
      </Card>
    );
  }

  return (
    <Card className="gap-0 overflow-hidden border-blue-200 bg-blue-50/60 py-0 shadow-sm dark:border-blue-800 dark:bg-blue-950/20">
      <CardContent className="grid gap-4 px-5 py-4 sm:grid-cols-[1fr_auto] sm:items-center">
        {/* Left: icon + title + badge + explanation */}
        <div className="flex items-start gap-3">
          <div className="mt-0.5 flex size-8 shrink-0 items-center justify-center rounded-full bg-blue-100 dark:bg-blue-900">
            <Lock className="size-4 text-blue-600 dark:text-blue-400" />
          </div>
          <div className="space-y-1.5">
            <p className="font-semibold">
              {php.isolated ? t("onTitle") : t("offTitle")}
            </p>
            <Badge variant="outline" className="border-blue-300 bg-blue-100/60 text-xs font-normal text-blue-700 dark:border-blue-700 dark:bg-blue-900/60 dark:text-blue-300">
              {php.isolated
                ? t("dedicatedBadge")
                : t("sharedBadge", { user: php.runs_as ?? "www-data" })}
            </Badge>
            <p className="text-sm text-muted-foreground">
              {php.isolated ? t("onBody") : t("offBody")}
            </p>
            {!php.isolated ? (
              <p className="text-xs text-muted-foreground">
                {t("isolateHelper")}
              </p>
            ) : null}
          </div>
        </div>

        {/* Right: button only, vertically centered */}
        {canManage ? (
          <div className="flex items-center">
            <Button
              type="button"
              variant={php.isolated ? "outline" : "default"}
              onClick={php.isolated ? onPutBack : onIsolate}
              disabled={busy}
            >
              {busy ? <Loader2 className="size-4 animate-spin" /> : null}
              {php.isolated ? t("putBackAction") : t("isolateAction")}
            </Button>
          </div>
        ) : null}
      </CardContent>
    </Card>
  );
}

function MemoryBudget({ budget, workers }) {
  const t = useTranslations("applications.php.budget");

  if (!budget.total) return null;

  const pct = (bytes) => Math.min(100, Math.round((bytes / budget.total) * 100));
  const mine = pct(budget.thisSite);
  const others = Math.max(0, Math.min(100 - mine, pct(budget.others)));

  return (
    <div className="space-y-2 rounded-lg border p-3">
      <div className="flex flex-wrap items-baseline justify-between gap-2">
        <p className="text-sm font-medium">
          {t("title", { value: formatBytes(budget.thisSite), workers: Number(workers) || 0 })}
        </p>
        <p
          className={cn(
            "text-xs",
            budget.overCommitted ? "text-destructive" : "text-muted-foreground",
          )}
        >
          {budget.overCommitted
            ? t("over", { total: formatBytes(budget.total) })
            : t("available", {
                value: formatBytes(budget.available),
                total: formatBytes(budget.total),
              })}
        </p>
      </div>

      <div className="flex h-2 overflow-hidden rounded-full bg-muted" aria-hidden>
        <div
          className={cn("h-full shrink-0", budget.overCommitted ? "bg-destructive" : "bg-primary")}
          style={{ width: `${mine}%` }}
        />
        <div className="h-full shrink-0 bg-muted-foreground/30" style={{ width: `${others}%` }} />
      </div>

      <p className="text-xs text-muted-foreground">
        {t("legend", { sites: Math.max(0, budget.sites - 1), value: formatBytes(budget.others) })}
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
      <Label label={label} directive={directive} />
      {children}
      {error ? <p className="text-sm text-destructive">{error}</p> : null}
    </FormItem>
  );
}

function Group({ icon: Icon, title, children }) {
  return (
    <div className="space-y-4 px-5 py-4">
      <p className="flex items-center gap-2 text-sm font-semibold">
        <Icon className="size-4 text-muted-foreground" />
        {title}
      </p>
      {children}
    </div>
  );
}

function Label({ label, directive }) {
  return (
    <FormLabel className="flex flex-wrap items-baseline gap-x-2">
      {label}
      {directive ? (
        <span className="font-mono text-[0.7rem] font-normal text-muted-foreground">
          {directive}
        </span>
      ) : null}
    </FormLabel>
  );
}

function NumberField({ form, name, label, directive, hint, disabled, min, max }) {
  return (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem>
          <Label label={label} directive={directive} />
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
          <Label label={label} directive={directive} />
          <FormControl>
            <Input
              {...field}
              spellCheck={false}
              disabled={disabled}
              className={cn(mono && "font-mono text-xs")}
            />
          </FormControl>
          <FormDescription>{hint}</FormDescription>
          <FormMessage />
        </FormItem>
      )}
    />
  );
}

function ToggleRow({ form, name, label, directive, disabled }) {
  return (
    <FormField
      control={form.control}
      name={name}
      render={({ field }) => (
        <FormItem className="flex items-center justify-between gap-4 rounded-lg border px-3 py-2.5">
          <Label label={label} directive={directive} />
          <FormControl>
            <Switch checked={field.value} onCheckedChange={field.onChange} disabled={disabled} />
          </FormControl>
        </FormItem>
      )}
    />
  );
}
