"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useFormatter, useTranslations } from "next-intl";
import { DENIED, DisabledReasonProvider } from "@/components/ui/reason-tooltip";
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
import { formatBytes, localizeSizeText } from "@/lib/format/bytes";
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
  const passwordUnreadable = redis?.has_password === null;
  const passwordLocked =
    !canManage || redis?.password_manageable === false || passwordUnreadable;
  const t = useTranslations("settings.performance");
  const tv = useTranslations("settings.validation");
  const router = useRouter();
  const { refreshAndWait } = useRefresh();
  const [removing, setRemoving] = useState(false);
  const [pendingRemoval, setPendingRemoval] = useState(false);
  const [applying, setApplying] = useState(false);
  const [changing, setChanging] = useState(false);

  useEffect(() => {
    if (!applying) return undefined;
    const timer = setTimeout(() => router.refresh(), 5000);
    return () => clearTimeout(timer);
  }, [applying, router]);

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
      : (localizeSizeText(redis?.memory_used_human, format) ?? null);

  const unsafe = redis?.unsafe_policies ?? [];
  const policies = REDIS_POLICIES.filter((policy) => !unsafe.includes(policy) || policy === defaults.maxmemory_policy);

  const form = useForm({
    resolver: zodResolver(redisFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  // Separate request: it's destructive and shouldn't ride along with another edit.
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
      toast.error(apiMessage(error, t("redis.removeFailed")));
    } finally {
      setPendingRemoval(false);
    }
  }

  async function onSubmit(values) {
    if (unsafe.includes(values.maxmemory_policy)) {
      form.setError("maxmemory_policy", { type: "manual", message: t("redis.unsafePolicy") });
      scrollToFirstError();
      return;
    }
    try {
      const payload = { ...values };
      if (!payload.password) delete payload.password;

      const response = await updateRedisSettings(payload);

      // 202: the password applies after this response, so don't refresh or say Saved.
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
    <DisabledReasonProvider denied={!canManage}>
      <Form {...form}>
        <form noValidate onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
          <Section
            icon={Database}
            title={t("redis.title")}
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
                <Row
                  label={t("redis.policy")}
                  hint={t("redis.policyHint")}
                  error={unsafe.includes(field.value) ? t("redis.unsafePolicy") : undefined}
                >
                  <Select
                    value={field.value}
                    onValueChange={field.onChange}
                    disabled={!canManage}
                  >
                    <FormControl>
                      <SelectTrigger className="redis-policy-trigger h-auto min-h-9 w-full data-[size=default]:h-auto py-1.5 text-left whitespace-normal *:data-[slot=select-value]:line-clamp-none *:data-[slot=select-value]:flex-wrap">
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {policies.map((policy) => (
                        <SelectItem key={policy} value={policy}>
                          {t(`redis.policies.${policy}`)}
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
  
            <FormField
              control={form.control}
              name="password"
              render={({ field }) => (
                <Row
                  wide
                  label={t("redis.password")}
                  hint={
                    passwordUnreadable
                      ? t("redis.passwordUnknown")
                      : redis?.password_manageable === false
                        ? t("redis.passwordLocked")
                        : changing
                          ? t("redis.passwordHint")
                          : showStored
                            ? t("redis.currentPasswordHint")
                            :
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
                  <div className="grid gap-x-6 gap-y-2 @3xl/section:grid-cols-2 @3xl/section:items-center">
                    <div className="min-w-0">
                    {showStored ? (
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
                                ? DENIED
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
