"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { CircleAlert, Database, KeyRound, Loader2 } from "lucide-react";
import { redisFormSchema, REDIS_POLICIES } from "@/lib/schemas/settings";
import { updateRedisSettings } from "@/lib/api/settings";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { validationMessage } from "@/lib/settings/validation-message";
import { Button } from "@/components/ui/button";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { Input } from "@/components/ui/input";
import { Progress } from "@/components/ui/progress";
import { formatBytes } from "@/lib/format/bytes";
import { memoryBytes, memoryInput } from "@/lib/settings/redis-memory";
import { PasswordInput } from "@/components/ui/password-input";
import { PasswordReveal } from "@/components/system-users/password-reveal";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";
import { Form, FormField, FormControl } from "@/components/ui/form";
import {
  Row,
  Section,
  SectionActions,
} from "@/components/settings/setting-row";

export function RedisForm({ redis, canManage, changedBy }) {
  // A password change also rewrites the panel's own REDIS_PASSWORD, so it needs a writable .env.
  // `has_password === null`: the stored credential does not connect, and the API 422s a change.
  const passwordUnreadable = redis?.has_password === null;
  const passwordLocked =
    !canManage || redis?.password_manageable === false || passwordUnreadable;
  const t = useTranslations("settings.performance");
  const tv = useTranslations("settings.validation");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [removing, setRemoving] = useState(false);
  const [pendingRemoval, setPendingRemoval] = useState(false);
  // Nothing reports when a 202 password change lands, so this never claims a result.
  const [applying, setApplying] = useState(false);
  // Swapped in only when replacing the password; normally the row shows the stored credential.
  const [changing, setChanging] = useState(false);

  // One automatic re-read so the common case settles; not a poll, as there is
  // nothing to poll for.
  useEffect(() => {
    if (!applying) return undefined;
    const timer = setTimeout(() => router.refresh(), 5000);
    return () => clearTimeout(timer);
  }, [applying, router]);

  // Falls through to the input when there is no stored value or the API withholds it.
  const showStored = Boolean(redis?.password) && !changing;

  const defaults = {
    maxmemory: memoryInput(redis?.maxmemory ?? "0"),
    maxmemory_policy: redis?.maxmemory_policy ?? "noeviction",
    password: "",
  };

  const format = useFormatter();
  const limitBytes = memoryBytes(redis?.maxmemory) || null;
  const usedLabel =
    typeof redis?.memory_used === "number"
      ? formatBytes(redis.memory_used, format)
      : (redis?.memory_used_human ?? null);

  const form = useForm({
    resolver: zodResolver(redisFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  // Its own request: the flag is destructive, so it must not ride along with
  // an unrelated memory-limit edit.
  async function removePassword() {
    setPendingRemoval(true);
    try {
      await updateRedisSettings({
        maxmemory: redis?.maxmemory ?? "0",
        maxmemory_policy: redis?.maxmemory_policy ?? "noeviction",
        remove_password: true,
      });
      await refreshAndWait();
      toast.success(t("redis.removed"));
      setRemoving(false);
    } catch (error) {
      // The fallback must describe the failure.
      toast.error(apiMessage(error, t("redis.removeFailed")));
    } finally {
      setPendingRemoval(false);
    }
  }

  async function onSubmit(values) {
    try {
      // An empty password field means "leave it alone", not "clear it" — the
      // API only touches the password when one is sent.
      const payload = { ...values };
      if (!payload.password) delete payload.password;

      const response = await updateRedisSettings(payload);

      // 202: the password is applied AFTER this response (the panel's current
      // credential is the one being replaced), so don't report "Saved" or refresh.
      if (response?.status === 202) {
        form.reset({ ...values, password: "" });
        setChanging(false);
        setApplying(true);
        return;
      }

      form.reset({ ...values, password: "" });
      await refreshAndWait();
      toast.success(t("redis.saved"));
      setChanging(false);
    } catch (error) {
      handleValidationError(error, form, { fallback: t("redis.saveFailed") });
    }
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Form {...form}>
        <form noValidate onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
          <Section
            icon={Database}
            title={t("redis.title")}
            description={t("redis.description")}
            readOnly={!canManage}
            changedBy={changedBy}
            actions={
              <SectionActions
                label={t("redis.save")}
                isDirty={form.formState.isDirty}
                pending={form.formState.isSubmitting}
                onDiscard={() => form.reset(defaults)}
                canManage={canManage}
              />
            }
          >
            {/* Stays until dismissed; a toast would fade before the change lands. */}
            {applying ? (
              <div
                role="status"
                className="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-warning/40 bg-warning/10 px-3.5 py-2.5 text-sm"
              >
                <span className="flex items-start gap-2">
                  <Loader2 className="mt-0.5 size-4 shrink-0 animate-spin text-warning motion-reduce:animate-none" />
                  {t("redis.passwordApplying")}
                </span>
                <Button
                  type="button"
                  variant="outline"
                  size="sm"
                  onClick={() => {
                    setApplying(false);
                    router.refresh();
                  }}
                >
                  {t("redis.passwordApplyingCheck")}
                </Button>
              </div>
            ) : null}

            {redis?.running === false ? (
              <p className="flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3.5 py-2.5 text-sm text-destructive">
                <CircleAlert className="size-4 shrink-0" />
                {t("redis.notRunning")}
              </p>
            ) : null}

            {redis?.password_out_of_sync === true ? (
              <div className="flex gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3.5 py-2.5 text-sm text-destructive">
                <CircleAlert className="mt-0.5 size-4 shrink-0" />
                <div className="space-y-1">
                  <p>{t("redis.outOfSync")}</p>
                  {canManage ? <p className="text-xs">{t("redis.outOfSyncFix")}</p> : null}
                </div>
              </div>
            ) : null}
  
            <FormField
              control={form.control}
              name="maxmemory"
              render={({ field }) => (
                <Row
                  label={t("redis.maxmemory")}
                  required
                  hint={t("redis.maxmemoryHint")}
                  error={validationMessage(
                    tv,
                    form.formState.errors.maxmemory?.message,
                  )}
                >
                  <FormControl>
                    <Input
                      placeholder="256mb"
                      className="font-mono"
                      autoComplete="off"
                      spellCheck={false}
                      disabled={!canManage}
                      {...field}
                    />
                  </FormControl>
                  {/* What it is using now, against the saved limit. */}
                  {usedLabel ? (
                    <div className="space-y-1 pt-1">
                      <p className="text-xs text-muted-foreground tabular-nums">
                        {limitBytes
                          ? t("redis.usageOfLimit", { used: usedLabel, limit: formatBytes(limitBytes, format) })
                          : t("redis.usage", { used: usedLabel })}
                      </p>
                      {limitBytes && typeof redis?.memory_used === "number" ? (
                        <Progress
                          value={Math.min(100, (redis.memory_used / limitBytes) * 100)}
                          aria-label={t("redis.usageOfLimit", { used: usedLabel, limit: formatBytes(limitBytes, format) })}
                        />
                      ) : null}
                    </div>
                  ) : null}
                </Row>
              )}
            />
  
            <FormField
              control={form.control}
              name="maxmemory_policy"
              render={({ field }) => (
                <Row label={t("redis.policy")} hint={t("redis.policyHint")}>
                  <Select
                    value={field.value}
                    onValueChange={field.onChange}
                    disabled={!canManage}
                  >
                    <FormControl>
                      {/* Wraps rather than clips: the plain-words label is long in Russian and on a phone. */}
                      <SelectTrigger className="redis-policy-trigger h-auto min-h-9 w-full data-[size=default]:h-auto py-1.5 text-left whitespace-normal *:data-[slot=select-value]:line-clamp-none *:data-[slot=select-value]:flex-wrap">
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    {/* Plain words first; Redis's own name after, for anyone matching docs. */}
                    <SelectContent>
                      {REDIS_POLICIES.map((policy) => (
                        <SelectItem key={policy} value={policy}>
                          {t(`redis.policies.${policy}`)}
                          {/* Only in the open list: in the closed box it pushed the label onto two lines.
                              A class, not data-slot: FormControl overwrites the trigger's data-slot. */}
                          <span className="ms-1.5 font-mono text-xs whitespace-nowrap text-muted-foreground in-[.redis-policy-trigger]:hidden">
                            {policy}
                          </span>
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                </Row>
              )}
            />
  
            {/* One row: the stored value, with replacing it as a deliberate step. */}
            <FormField
              control={form.control}
              name="password"
              render={({ field }) => (
                <Row
                  wide
                  label={t("redis.password")}
                  hint={
                    // The full sentence lives here; the input is too narrow for it.
                    passwordUnreadable
                      ? t("redis.passwordUnknown")
                      : redis?.password_manageable === false
                        ? t("redis.passwordLocked")
                        : changing
                          ? t("redis.passwordHint")
                          : showStored
                            ? t("redis.currentPasswordHint")
                            : // Withheld from view-only users, so a missing value does not mean "none".
                              redis?.has_password
                              ? canManage
                                ? t("redis.passwordSet")
                                : null
                              : t("redis.passwordHintNone")
                  }
                  error={validationMessage(
                    tv,
                    form.formState.errors.password?.message,
                  )}
                >
                  {/* The field keeps the same column width as Memory limit above it; its
                      buttons sit beside it in the second column (Krishna, 12 Oct). */}
                  <div className="grid gap-x-6 gap-y-2 @3xl/section:grid-cols-2 @3xl/section:items-center">
                    <div className="min-w-0">
                    {showStored ? (
                      // Shaped like the inputs beside it, at their full width, with its
                      // two buttons inside: a short grey strip read as a different field.
                      <PasswordReveal
                        password={redis.password}
                        className="min-h-9 rounded-md border border-input bg-muted/40 pr-1 text-left dark:bg-input/30 [&>code]:bg-transparent"
                      />
                    ) : !canManage ? (
                      <p className="text-sm">
                        {redis?.has_password
                          ? t("redis.passwordSet")
                          : t("redis.passwordNone")}
                      </p>
                    ) : (
                      <>
                        <FormControl>
                          <PasswordInput
                            autoComplete="new-password"
                            autoFocus={changing}
                            placeholder={
                              passwordUnreadable
                                ? t("redis.passwordUnknownShort")
                                : t("redis.newPasswordPlaceholder")
                            }
                            disabled={passwordLocked}
                            disabledReason={
                              !canManage
                                ? t("noPermission")
                                : passwordUnreadable
                                  ? t("redis.passwordUnknown")
                                  : t("passwordNotManageable")
                            }
                            {...field}
                          />
                        </FormControl>

                      </>
                    )}
                    </div>

                    {/* Only when there is something to go back to. */}
                    {changing && canManage ? (
                      <Button
                        type="button"
                        variant="ghost"
                        onClick={() => {
                          setChanging(false);
                          form.resetField("password");
                        }}
                      >
                        {t("redis.changeCancel")}
                      </Button>
                    ) : null}

                    {/* Removing is its own action (`remove_password`), not an
                        empty value: Laravel rewrites "" to null before validation. */}
                    {!passwordLocked && !changing ? (
                      <div className="flex shrink-0 flex-wrap items-center gap-2">
                        {showStored ? (
                          <Button
                            type="button"
                            variant="outline"
                            onClick={() => setChanging(true)}
                          >
                            {t("redis.changePassword")}
                          </Button>
                        ) : null}

                        {redis?.has_password === true ? (
                          // The `destructive` variant, so it pairs visually with
                          // the outlined "Change password".
                          <Button
                            type="button"
                            variant="destructive"
                            onClick={() => setRemoving(true)}
                          >
                            {t("redis.removePassword")}
                          </Button>
                        ) : null}
                      </div>
                    ) : null}
                  </div>
                </Row>
              )}
            />
          </Section>
        </form>


        <ConfirmDialog
          open={removing}
          onOpenChange={(open) => !open && setRemoving(false)}
          icon={KeyRound}
          tone="destructive"
          title={t("redis.removeTitle")}
          description={t("redis.removeDescription")}
          cancelLabel={t("redis.removeCancel")}
          confirmLabel={t("redis.removeSubmit")}
          pending={pendingRemoval}
          onConfirm={removePassword}
        />
      </Form>
    </DisabledReasonProvider>
  );
}
