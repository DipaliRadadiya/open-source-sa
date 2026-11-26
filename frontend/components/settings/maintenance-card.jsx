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
  return (
    <DisabledReasonProvider denied={!canManage}>
      <div className="space-y-4">
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

function UpdateStatus({ updates, action = null, meta = null, details = null }) {
  const t = useTranslations("settings.maintenance");

  const security = updates?.security_updates_available ?? null;

  const failed = updates?.unattended_last_result === "failed";
  const unreadable = updates?.unattended_log_readable === false;
  const neverRun =
    updates?.security_updates_enabled && !updates?.unattended_last_run_at;

  if (security == null && !failed && !unreadable && !neverRun) {
    return (
      <div className="space-y-3">
        <div className="flex flex-wrap items-center gap-3">
          {action}
          {meta}
        </div>
        {details}
      </div>
    );
  }

  const tone = failed
    ? "border-destructive/30 bg-destructive/5 text-destructive"
    : unreadable || security == null
      ?
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

      {meta}
      {action ? <span className="ms-auto">{action}</span> : null}

      {failed && updates?.unattended_last_error ? (
        <p className="w-full font-mono text-xs wrap-anywhere opacity-90">
          {updates.unattended_last_error}
        </p>
      ) : null}

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

      {details ? <div className="w-full space-y-3 pt-1.5">{details}</div> : null}
    </div>
  );
}

function RunSecurityUpdates({ run, canManage, frame }) {
  const t = useTranslations("settings.maintenance");
  const router = useRouter();
  const [confirming, setConfirming] = useState(false);
  const [starting, setStarting] = useState(false);
  const [current, setCurrent] = useState(run ?? null);
  const [synced, setSynced] = useState(run ?? null);
  const [reconnecting, setReconnecting] = useState(false);

  // During render, not in an effect, or the stale run renders first.
  if (run !== synced) {
    setSynced(run);
    setCurrent(run ?? null);
  }

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

  const action = (
        <ReasonTooltip reason={reason}>
          <Button
            type="button"
            variant="default"
            size="sm"
            className="h-auto min-h-8 py-1.5 text-left whitespace-normal"
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
  );
  const meta = (
    <>
        {running && reconnecting ? (
          <span className="flex items-center gap-2 text-xs text-muted-foreground">
            <WifiOff className="size-3.5 shrink-0" />
            {t("updates.runReconnecting")}
          </span>
        ) : null}

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
    </>
  );
  const hasDetails = Boolean((current?.reboot_required_after && !running) || current?.status === "failed" || current?.output);
  const details = hasDetails ? (
    <>
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

    </>
  ) : null;

  return (
    <>
      {frame ? (
        frame({ action, meta, details })
      ) : (
        <div className="space-y-3">
          <div className="flex flex-wrap items-center gap-3">
            {action}
            {meta}
          </div>
          {details}
        </div>
      )}
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
    </>
  );
}

function UpdatesSection({ updates, canManage }) {
  const t = useTranslations("settings.maintenance");
  const tv = useTranslations("settings.validation");
  const { refreshAndWait } = useRefresh();

  const defaults = {
    security_updates_enabled: updates?.security_updates_enabled ?? false,
    auto_reboot: updates?.auto_reboot ?? false,
    // The API also accepts "now", which a time field can't show.
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
          <RunSecurityUpdates
            run={updates?.security_update ?? null}
            canManage={canManage}
            frame={(parts) => <UpdateStatus updates={updates} {...parts} />}
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
                wide={!enabled}
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

          {enabled ? (
            <InfoRow label={t("schedule.nextLabel")}>
              <span className="text-sm tabular-nums">
                {schedule?.next_run_human ?? t("schedule.notScheduled")}
              </span>
            </InfoRow>
          ) : null}
        </Section>
      </form>
    </Form>
  );
}

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

      if (minutes === 0) {
        start();
      } else {
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

  if (!canManage && !rebootRequired && !pendingReboot?.scheduled && !pendingRebootFailed) return null;

  return (
    <Section
      icon={TriangleAlert}
      title={t("reboot.title")}
      tone="destructive"
      badge={
        rebootRequired ? (
          <Badge variant="warning" className="font-normal">
            {t("summary.pendingYes")}
          </Badge>
        ) : null
      }
      actions={
        canManage ? (
          <Button type="button" variant="destructive" onClick={() => setConfirming(true)}>
            {t("reboot.action")}
          </Button>
        ) : null
      }
    >
      {/* Read from systemd, so a reboot scheduled from a shell shows too. */}
      {pendingReboot?.scheduled ? (
        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
          <span className="flex items-start gap-2">
            <CalendarClock className="mt-0.5 size-4 shrink-0 text-warning" />
            {pendingReboot.at ? (
              <span className="flex flex-col gap-0.5">
                {typeof pendingReboot.seconds_remaining === "number" ? (
                  <RebootCountdown
                    key={pendingReboot.seconds_remaining}
                    secondsRemaining={pendingReboot.seconds_remaining}
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
        <p className="flex items-start gap-2 rounded-lg border p-3 text-sm text-muted-foreground">
          <CircleAlert className="mt-0.5 size-4 shrink-0" />
          {t("reboot.pendingUnknown")}
        </p>
      ) : null}

      {rebootRequired ? (
        <p className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
          <RotateCcw className="mt-0.5 size-4 shrink-0 text-warning" />
          {t("rebootRequired.title")}
        </p>
      ) : null}

      {canManage ? (
      <>
      <p className="text-sm text-muted-foreground">
        {t("reboot.description")}
      </p>

      <InfoRow label={t("reboot.when")}>
        <Select value={delay} onValueChange={setDelay} disabled={!canManage}>
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
      </>
      ) : null}

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
