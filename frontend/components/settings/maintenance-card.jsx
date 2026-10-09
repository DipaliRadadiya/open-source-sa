"use client";

import { useEffect, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import {
  DisabledReasonProvider,
  ReasonTooltip,
} from "@/components/ui/reason-tooltip";
import { cn } from "@/lib/utils";
import {
  CalendarClock,
  ChevronDown,
  CircleAlert,
  CircleCheck,
  Download,
  Loader2,
  Lock,
  Power,
  RotateCcw,
  ShieldCheck,
  SquareTerminal,
  TriangleAlert,
  WifiOff,
} from "lucide-react";
import {
  updatesFormSchema,
  scheduleFormSchema,
  MAX_DAY_OF_MONTH,
  REBOOT_DELAY_OPTIONS,
} from "@/lib/schemas/settings";
import {
  updateUpdateSettings,
  updateRebootSchedule,
  rebootServer,
  cancelReboot,
  runSecurityUpdates,
  getSecurityUpdateRun,
} from "@/lib/api/settings";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { validationMessage } from "@/lib/settings/validation-message";
import { apiMessage } from "@/lib/api/error-message";
import { useServerRestart } from "@/components/sections/server-restart-overlay";
import { RebootCountdown } from "@/components/settings/reboot-countdown";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import {
  Collapsible,
  CollapsibleContent,
  CollapsibleTrigger,
} from "@/components/ui/collapsible";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Form, FormField, FormControl } from "@/components/ui/form";
import {
  Row,
  InfoRow,
  Section,
  SectionActions,
} from "@/components/settings/setting-row";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

const DAYS_OF_MONTH = Array.from({ length: MAX_DAY_OF_MONTH }, (_, i) => i + 1);

// Three cards: each commits to its own endpoint; manual restart persists nothing.
export function MaintenanceCard({
  updates,
  schedule,
  rebootRequired,
  presets,
  presetsFailed,
  canManage,
  pendingReboot,
  pendingRebootFailed,
}) {
  const tc = useTranslations("settings.common");

  return (
    // Gives each disabled control the same permission reason as the banner below.
    <DisabledReasonProvider reason={canManage ? null : tc("noPermission")}>
      <div className="space-y-4">
        {/* Stated once above the three cards. */}
        {!canManage ? (
          <p className="flex w-fit items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2 text-sm text-muted-foreground">
            <Lock className="size-3.5 shrink-0" />
            {tc("readOnly")}
          </p>
        ) : null}

        <UpdatesSection updates={updates} canManage={canManage} />

        <ScheduleSection
          schedule={schedule}
          presets={presets}
          presetsFailed={presetsFailed}
          canManage={canManage}
        />

        <ManualSection
          canManage={canManage}
          rebootRequired={rebootRequired}
          pendingReboot={pendingReboot}
          pendingRebootFailed={pendingRebootFailed}
        />
      </div>
    </DisabledReasonProvider>
  );
}

// An "on" toggle is no evidence anything ran. `unattended_last_result` tells never-run from failed.
function UpdateStatus({ updates }) {
  const t = useTranslations("settings.maintenance");

  // Only the security count: every control here acts on security updates alone.
  const security = updates?.security_updates_available ?? null;

  const failed = updates?.unattended_last_result === "failed";
  // Could not open the log, which differs from the log holding no run.
  const unreadable = updates?.unattended_log_readable === false;
  const neverRun =
    updates?.security_updates_enabled && !updates?.unattended_last_run_at;

  // A failed run is reported even when the apt-check count is null.
  if (security == null && !failed && !unreadable && !neverRun) return null;

  const tone = failed
    ? "border-destructive/30 bg-destructive/5 text-destructive"
    : unreadable || security == null
      ? // Not green: the count is unknown.
        "border-warning/40 bg-warning/10"
      : security > 0
        ? "border-warning/40 bg-warning/10"
        : "border-success/30 bg-success/5";

  return (
    <div
      className={cn(
        "flex flex-wrap items-center gap-x-3 gap-y-1 rounded-lg border px-3.5 py-2.5 text-sm",
        tone,
      )}
    >
      {failed ? (
        <CircleAlert className="size-4 shrink-0" />
      ) : unreadable || security == null || security > 0 ? (
        <TriangleAlert className="size-4 shrink-0 text-warning" />
      ) : (
        <CircleCheck className="size-4 shrink-0 text-success" />
      )}

      {/* `null` is "nobody knows", `0` is "nothing waiting". */}
      {security == null ? null : (
        <span className="font-medium">
          {security > 0
            ? t("updates.pending", { count: security })
            : t("updates.upToDate")}
        </span>
      )}

      <span className="text-xs text-muted-foreground">
        {updates?.lists_refreshed_at_human
          ? t("updates.checked", { when: updates.lists_refreshed_at_human })
          : t("updates.neverChecked")}
      </span>

      {/* Only worth saying when the automation claims to be doing something. */}
      {failed ? (
        <span className="text-xs">{t("updates.lastFailed")}</span>
      ) : unreadable ? (
        <span className="text-xs">{t("updates.logUnreadable")}</span>
      ) : neverRun ? (
        <span className="text-xs text-muted-foreground">
          {t("updates.neverRun")}
        </span>
      ) : updates?.unattended_last_run_at_human ? (
        <span className="text-xs text-muted-foreground">
          {t("updates.lastRun", { when: updates.unattended_last_run_at_human })}
        </span>
      ) : null}

      {/* Verbatim and untranslated (searchable); `wrap-anywhere` for long package names. */}
      {failed && updates?.unattended_last_error ? (
        <p className="w-full font-mono text-xs wrap-anywhere opacity-90">
          {updates.unattended_last_error}
        </p>
      ) : null}

      {/* Absent without `setting,manage` or when no excerpt could be built. */}
      {failed && updates?.unattended_last_log ? (
        <Collapsible className="group/log w-full">
          <CollapsibleTrigger asChild>
            <Button variant="ghost" size="sm" className="-ml-2 h-8 gap-2 px-2">
              <SquareTerminal className="size-4" />
              {t("updates.viewLog")}
              <ChevronDown className="size-4 transition-transform group-data-[state=open]/log:rotate-180" />
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="pt-2">
            {updates.unattended_last_log_truncated ? (
              <p className="mb-1 text-xs text-muted-foreground">
                {t("updates.logTruncated")}
              </p>
            ) : null}
            <pre className="max-h-80 overflow-auto rounded-md border bg-zinc-950 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-100">
              {updates.unattended_last_log}
            </pre>
          </CollapsibleContent>
        </Collapsible>
      ) : null}
    </div>
  );
}

// Runs unattended-upgrades' own binary, so it installs exactly what the toggle allows.
function RunSecurityUpdates({ run, canManage }) {
  const t = useTranslations("settings.maintenance");
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [starting, setStarting] = useState(false);
  // Seeded from the server render so a reload mid-upgrade still shows it.
  const [current, setCurrent] = useState(run ?? null);
  const [synced, setSynced] = useState(run ?? null);
  // The upgrade can restart php-fpm and the frontend; shown after the second failed poll.
  const [reconnecting, setReconnecting] = useState(false);

  // During render, not an effect: an effect would render the stale run first.
  if (run !== synced) {
    setSynced(run);
    setCurrent(run ?? null);
  }

  // Derived, not stored, so a spinner cannot outlive the run.
  const running = current?.status === "running";

  useEffect(() => {
    if (!running) return undefined;

    let cancelled = false;
    let misses = 0;

    const interval = window.setInterval(async () => {
      try {
        const { data } = await getSecurityUpdateRun();
        if (cancelled) return;

        misses = 0;
        setReconnecting(false);

        const next = data?.security_update ?? null;
        setCurrent(next);

        if (next && next.status !== "running") {
          // Counts, last-run line and reboot banner are all server-rendered and now stale.
          router.refresh();
        }
      } catch {
        if (cancelled) return;
        misses += 1;
        if (misses > 1) setReconnecting(true);
      }
    }, 3000);

    return () => {
      cancelled = true;
      window.clearInterval(interval);
    };
  }, [running, router]);

  async function start() {
    setStarting(true);
    try {
      const { data } = await runSecurityUpdates();
      setCurrent(data?.security_update ?? null);
      setConfirming(false);
      toast.success(t("updates.runStarted"));
    } catch (error) {
      // 409 carries the run already going; adopt it rather than report an error.
      const existing = error?.response?.data?.security_update;
      if (existing) {
        setCurrent(existing);
        setConfirming(false);
      }
      toast.error(apiMessage(error, t("updates.runFailed")));
    } finally {
      setStarting(false);
    }
  }

  const reason = !canManage
    ? null
    : running
      ? t("updates.runInProgress")
      : null;

  return (
    <div className="space-y-3">
      <div className="flex flex-wrap items-center gap-3">
        <ReasonTooltip reason={reason}>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!canManage || running || starting}
            onClick={() => setConfirming(true)}
          >
            {running || starting ? (
              <Loader2 className="size-4 animate-spin" />
            ) : (
              <Download className="size-4" />
            )}
            {running ? t("updates.running") : t("updates.runNow")}
          </Button>
        </ReasonTooltip>

        {running && reconnecting ? (
          <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <WifiOff className="size-3.5 shrink-0" />
            {t("updates.runReconnecting")}
          </span>
        ) : null}

        {/* The last panel-initiated run, a different fact from the last automatic one. */}
        {current && !running ? (
          <span className="text-xs text-muted-foreground">
            {current.status === "succeeded"
              ? current.packages_upgraded
                ? t("updates.runInstalled", {
                    count: current.packages_upgraded,
                    when: current.finished_at_human ?? "",
                  })
                : t("updates.runNothing")
              : t("updates.runFailedAt", {
                  when: current.finished_at_human ?? "",
                })}
          </span>
        ) : null}
      </div>

      {/* Never performed here: rebooting is its own confirmed action. */}
      {current?.reboot_required_after && !running ? (
        <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
          <RotateCcw className="mt-0.5 size-4 shrink-0 text-warning" />
          {t("updates.runRebootRequired")}
        </p>
      ) : null}

      {current?.status === "failed" ? (
        <p className="text-sm text-destructive">
          {t(`updates.runReasons.${current.reason ?? "unknown"}`)}
        </p>
      ) : null}

      {/* apt does not report how much is left, so no progress bar. */}
      {current?.output ? (
        <Collapsible defaultOpen={running} className="group/run">
          <CollapsibleTrigger asChild>
            <Button variant="ghost" size="sm" className="-ml-2 h-8 gap-2 px-2">
              <SquareTerminal className="size-4" />
              {t("updates.runOutput")}
              <ChevronDown className="size-4 transition-transform group-data-[state=open]/run:rotate-180" />
            </Button>
          </CollapsibleTrigger>
          <CollapsibleContent className="pt-2">
            <pre className="max-h-80 overflow-auto rounded-md border bg-zinc-950 p-3 font-mono text-xs leading-relaxed whitespace-pre-wrap text-zinc-100">
              {current.output}
            </pre>
          </CollapsibleContent>
        </Collapsible>
      ) : null}

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        icon={Download}
        title={t("updates.runConfirmTitle")}
        description={t("updates.runConfirmBody")}
        cancelLabel={t("updates.runConfirmCancel")}
        confirmLabel={t("updates.runConfirmSubmit")}
        pending={starting}
        onConfirm={start}
      />
    </div>
  );
}

function UpdatesSection({ updates, canManage }) {
  const t = useTranslations("settings.maintenance");
  const tv = useTranslations("settings.validation");
  const { refreshAndWait } = useRefresh();

  const defaults = {
    security_updates_enabled: updates?.security_updates_enabled ?? false,
    auto_reboot: updates?.auto_reboot ?? false,
    // The API also accepts "now", which a time field can't express; it shows as an editable time.
    reboot_time: /^\d{2}:\d{2}$/.test(updates?.reboot_time ?? "")
      ? updates.reboot_time
      : "03:00",
    reboot_with_users: updates?.reboot_with_users ?? false,
  };

  const form = useForm({
    resolver: zodResolver(updatesFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  const autoReboot = useWatch({ control: form.control, name: "auto_reboot" });

  async function onSubmit(values) {
    try {
      await updateUpdateSettings(values);
      form.reset(values);
      await refreshAndWait();
      toast.success(t("updates.saved"));
    } catch (error) {
      handleValidationError(error, form, { fallback: t("updates.saveFailed") });
    }
  }

  return (
    <Form {...form}>
      <form noValidate onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
        <Section
          icon={ShieldCheck}
          title={t("updates.title")}
          description={t("updates.description")}
          actions={
            <SectionActions
              label={t("updates.save")}
              isDirty={form.formState.isDirty}
              pending={form.formState.isSubmitting}
              onDiscard={() => form.reset(defaults)}
              canManage={canManage}
            />
          }
        >
          {/* What is actually waiting; the switches below only describe intent. */}
          <UpdateStatus updates={updates} />

          <RunSecurityUpdates
            run={updates?.security_update ?? null}
            canManage={canManage}
          />

          <FormField
            control={form.control}
            name="security_updates_enabled"
            render={({ field }) => (
              <Row
                toggle
                label={t("updates.security")}
                hint={t("updates.securityHint")}
              >
                <FormControl>
                  <Switch
                    checked={field.value}
                    onCheckedChange={field.onChange}
                    disabled={!canManage}
                  />
                </FormControl>
              </Row>
            )}
          />

          <FormField
            control={form.control}
            name="auto_reboot"
            render={({ field }) => (
              <Row
                toggle
                label={t("updates.afterUpdate")}
                hint={t("updates.afterUpdateHint")}
              >
                <FormControl>
                  <Switch
                    checked={field.value}
                    onCheckedChange={field.onChange}
                    disabled={!canManage}
                  />
                </FormControl>
              </Row>
            )}
          />

          {/* Shown only when automatic restart is on. */}
          {autoReboot ? (
            <>
              <FormField
                control={form.control}
                name="reboot_time"
                render={({ field }) => (
                  <Row
                    label={t("updates.rebootTime")}
                    hint={t("updates.rebootTimeHint")}
                    required
                    error={validationMessage(
                      tv,
                      form.formState.errors.reboot_time?.message,
                    )}
                  >
                    <FormControl>
                      <Input
                        placeholder="03:00"
                        type="time"
                        className="w-full font-mono"
                        disabled={!canManage}
                        {...field}
                      />
                    </FormControl>
                  </Row>
                )}
              />

              <FormField
                control={form.control}
                name="reboot_with_users"
                render={({ field }) => (
                  <Row
                    toggle
                    label={t("updates.withUsers")}
                    hint={t("updates.withUsersHint")}
                  >
                    <FormControl>
                      <Switch
                        checked={field.value}
                        onCheckedChange={field.onChange}
                        disabled={!canManage}
                      />
                    </FormControl>
                  </Row>
                )}
              />
            </>
          ) : null}
        </Section>
      </form>
    </Form>
  );
}

function ScheduleSection({ schedule, presets, presetsFailed, canManage }) {
  const t = useTranslations("settings.maintenance");
  const { refreshAndWait } = useRefresh();

  const defaults = {
    enabled: schedule?.enabled ?? false,
    frequency: schedule?.frequency ?? "weekly",
    // The API adds a few minutes past the hour to avoid every other :00 cron job.
    hour: schedule?.hour ?? 3,
    day_of_week: schedule?.day_of_week ?? 0,
    day_of_month: schedule?.day_of_month ?? 1,
  };

  const form = useForm({
    resolver: zodResolver(scheduleFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  const enabled = useWatch({ control: form.control, name: "enabled" });
  const frequency = useWatch({ control: form.control, name: "frequency" });

  async function onSubmit(values) {
    try {
      // Off removes the cron file, so no cadence is sent with it.
      await updateRebootSchedule(values.enabled ? values : { enabled: false });
      form.reset(values);
      await refreshAndWait();
      toast.success(
        values.enabled ? t("schedule.saved") : t("schedule.turnedOff"),
      );
    } catch (error) {
      handleValidationError(error, form, { fallback: t("schedule.saveFailed") });
    }
  }

  return (
    <Form {...form}>
      <form noValidate onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
        <Section
          icon={CalendarClock}
          title={t("schedule.title")}
          description={t("schedule.description")}
          actions={
            <SectionActions
              label={t("schedule.save")}
              isDirty={form.formState.isDirty}
              pending={form.formState.isSubmitting}
              onDiscard={() => form.reset(defaults)}
              canManage={canManage}
            />
          }
        >
          <FormField
            control={form.control}
            name="enabled"
            render={({ field }) => (
              <Row
                toggle
                label={t("schedule.enable")}
                hint={
                  presetsFailed
                    ? t("schedule.optionsFailedHint")
                    : t("schedule.enableHint")
                }
              >
                <FormControl>
                  <Switch
                    checked={field.value}
                    onCheckedChange={field.onChange}
                    disabled={!canManage || presetsFailed}
                  />
                </FormControl>
              </Row>
            )}
          />

          {enabled && !presetsFailed ? (
            <>
              <FormField
                control={form.control}
                name="frequency"
                render={({ field }) => (
                  <Row label={t("schedule.frequency")}>
                    <Select
                      value={field.value}
                      onValueChange={field.onChange}
                      disabled={!canManage}
                    >
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {presets.frequencies.map((option) => (
                          <SelectItem key={option.value} value={option.value}>
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Row>
                )}
              />

              {frequency === "weekly" ? (
                <FormField
                  control={form.control}
                  name="day_of_week"
                  render={({ field }) => (
                    <Row label={t("schedule.dayOfWeek")} hint={t("schedule.dayOfWeekHint")}>
                      <Select
                        value={String(field.value)}
                        onValueChange={(value) => field.onChange(Number(value))}
                        disabled={!canManage}
                      >
                        <FormControl>
                          <SelectTrigger className="w-full">
                            <SelectValue />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {presets.days_of_week.map((option) => (
                            <SelectItem
                              key={option.value}
                              value={String(option.value)}
                            >
                              {option.label}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </Row>
                  )}
                />
              ) : null}

              {frequency === "monthly" ? (
                <FormField
                  control={form.control}
                  name="day_of_month"
                  render={({ field }) => (
                    <Row label={t("schedule.dayOfMonth")} hint={t("schedule.dayOfMonthHint")}>
                      <Select
                        value={String(field.value)}
                        onValueChange={(value) => field.onChange(Number(value))}
                        disabled={!canManage}
                      >
                        <FormControl>
                          <SelectTrigger className="w-full">
                            <SelectValue />
                          </SelectTrigger>
                        </FormControl>
                        <SelectContent>
                          {DAYS_OF_MONTH.map((day) => (
                            <SelectItem key={day} value={String(day)}>
                              {day}
                            </SelectItem>
                          ))}
                        </SelectContent>
                      </Select>
                    </Row>
                  )}
                />
              ) : null}

              <FormField
                control={form.control}
                name="hour"
                render={({ field }) => (
                  <Row
                    label={t("schedule.hour")}
                    hint={t("schedule.whenHint", {
                      timezone: schedule?.timezone ?? t("schedule.serverTime"),
                    })}
                  >
                    <Select
                      value={String(field.value)}
                      onValueChange={(value) => field.onChange(Number(value))}
                      disabled={!canManage}
                    >
                      <FormControl>
                        <SelectTrigger className="w-full">
                          <SelectValue />
                        </SelectTrigger>
                      </FormControl>
                      <SelectContent>
                        {presets.hours.map((option) => (
                          <SelectItem
                            key={option.value}
                            value={String(option.value)}
                          >
                            {option.label}
                          </SelectItem>
                        ))}
                      </SelectContent>
                    </Select>
                  </Row>
                )}
              />
            </>
          ) : null}

          {/* Reported, not set: the API computes it from the expression on disk. */}
          <InfoRow label={t("schedule.nextLabel")}>
            <span className="text-sm tabular-nums">
              {enabled && schedule?.next_run_human
                ? schedule.next_run_human
                : t("schedule.notScheduled")}
            </span>
          </InfoRow>
        </Section>
      </form>
    </Form>
  );
}

// Nothing to persist, so no Save.
function ManualSection({
  canManage,
  rebootRequired,
  pendingReboot,
  pendingRebootFailed,
}) {
  const t = useTranslations("settings.maintenance");
  const { refreshAndWait } = useRefresh();
  const { start } = useServerRestart();
  const [delay, setDelay] = useState("0");
  const [confirming, setConfirming] = useState(false);
  const [pending, setPending] = useState(false);
  const [cancelling, setCancelling] = useState(false);

  async function cancelPending() {
    setCancelling(true);
    try {
      await cancelReboot();
      await refreshAndWait();
      toast.success(t("reboot.cancelled"));
    } catch (error) {
      toast.error(apiMessage(error, t("reboot.cancelFailed")));
    } finally {
      setCancelling(false);
    }
  }

  async function confirm() {
    setPending(true);
    try {
      const minutes = Number(delay);
      const { data } = await rebootServer(minutes);
      setConfirming(false);

      // Only an immediate restart shows the curtain; a scheduled one has not started.
      if (minutes === 0) {
        start();
      } else {
        // `at` is the server's clock; "in N minutes" is a fallback, as the browser's may drift.
        const at = data?.reboot?.at;
        await refreshAndWait();
        toast.success(
          at
            ? t("reboot.scheduledAt", { at })
            : t("reboot.scheduled", { minutes }),
        );
      }
    } catch (error) {
      toast.error(apiMessage(error, t("reboot.failed")));
    } finally {
      setPending(false);
    }
  }

  return (
    <Section
      icon={TriangleAlert}
      title={t("reboot.title")}
      description={t("reboot.summary")}
      tone="destructive"
      badge={
        rebootRequired ? (
          <Badge variant="warning" className="font-normal">
            {t("summary.pendingYes")}
          </Badge>
        ) : null
      }
      actions={
        <Button
          type="button"
          variant="destructive"
          disabled={!canManage}
          onClick={() => setConfirming(true)}
        >
          {t("reboot.action")}
        </Button>
      }
    >
      {/* Read from systemd, so one scheduled from a shell shows up too. */}
      {pendingReboot?.scheduled ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
          <span className="flex items-start gap-2">
            <CalendarClock className="mt-0.5 size-4 shrink-0 text-warning" />
            {/* `at` can be null on a pending shutdown with no systemd timestamp. */}
            {pendingReboot.at ? (
              <span className="flex flex-col gap-0.5">
                {typeof pendingReboot.seconds_remaining === "number" ? (
                  <RebootCountdown
                    // Keyed so a refreshed measurement re-anchors the deadline.
                    key={pendingReboot.seconds_remaining}
                    secondsRemaining={pendingReboot.seconds_remaining}
                    // At zero this server is going down; the curtain hard-reloads when it is back.
                    onElapsed={start}
                  />
                ) : null}
                <span className="text-muted-foreground">
                  {t("reboot.pendingAt", { at: pendingReboot.at })}
                </span>
              </span>
            ) : (
              t("reboot.pendingUnknownTime")
            )}
          </span>
          <Button
            type="button"
            variant="outline"
            size="sm"
            disabled={!canManage || cancelling}
            onClick={cancelPending}
          >
            {cancelling ? <Loader2 className="size-4 animate-spin" /> : null}
            {t("reboot.cancel")}
          </Button>
        </div>
      ) : pendingRebootFailed ? (
        // Not the same as "nothing scheduled".
        <p className="flex items-start gap-2 rounded-lg border p-3 text-sm text-muted-foreground">
          <CircleAlert className="mt-0.5 size-4 shrink-0" />
          {t("reboot.pendingUnknown")}
        </p>
      ) : null}

      {/* Inline because it is needed before pressing. */}
      {rebootRequired ? (
        <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
          <RotateCcw className="mt-0.5 size-4 shrink-0 text-warning" />
          {t("rebootRequired.title")}
        </p>
      ) : null}

      <p className="text-sm text-muted-foreground">
        {t("reboot.description")}
      </p>

      <InfoRow label={t("reboot.when")}>
        <Select value={delay} onValueChange={setDelay} disabled={!canManage}>
          {/* InfoRow's label is not a <label>, so the trigger carries the same words. */}
          <SelectTrigger id="reboot-delay" aria-label={t("reboot.when")} className="w-full">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            {REBOOT_DELAY_OPTIONS.map((minutes) => (
              <SelectItem key={minutes} value={String(minutes)}>
                {minutes === 0
                  ? t("reboot.now")
                  : t("reboot.inMinutes", { minutes })}
              </SelectItem>
            ))}
          </SelectContent>
        </Select>
      </InfoRow>

      <ConfirmDialog
        open={confirming}
        onOpenChange={setConfirming}
        icon={Power}
        tone="destructive"
        title={t("reboot.confirmTitle")}
        description={
          Number(delay) === 0
            ? t("reboot.confirmNow")
            : t("reboot.confirmDelayed", { minutes: Number(delay) })
        }
        cancelLabel={t("reboot.confirmCancel")}
        confirmLabel={t("reboot.confirmSubmit")}
        pending={pending}
        onConfirm={confirm}
      />
    </Section>
  );
}
