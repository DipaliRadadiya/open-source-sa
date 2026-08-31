"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
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
import { PasswordInput } from "@/components/ui/password-input";
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
  // Changing the password also rewrites the panel's own REDIS_PASSWORD, so an
  // install that cannot write its .env cannot do this at all.
  const passwordLocked = !canManage || redis?.password_manageable === false;
  const t = useTranslations("settings.performance");
  const tv = useTranslations("settings.validation");
  const router = useRouter();
  const [removing, setRemoving] = useState(false);
  const [pendingRemoval, setPendingRemoval] = useState(false);
  // A password change is in flight (HTTP 202). There is no endpoint that
  // reports when it lands, and `has_password` cannot answer it either — it is
  // already true when one password is being replaced by another. So this state
  // says what was submitted and offers a re-read, and never claims a result it
  // did not observe.
  const [applying, setApplying] = useState(false);

  // One automatic re-read, so the common case settles without being asked.
  // Not a poll: with nothing to poll for, repeating the request would only
  // redraw the same page.
  useEffect(() => {
    if (!applying) return undefined;
    const timer = setTimeout(() => router.refresh(), 5000);
    return () => clearTimeout(timer);
  }, [applying, router]);

  const defaults = {
    maxmemory: redis?.maxmemory ?? "0",
    maxmemory_policy: redis?.maxmemory_policy ?? "noeviction",
    password: "",
  };

  const form = useForm({
    resolver: zodResolver(redisFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  // Its own request: the flag is destructive, so it must not ride along with
  // an unrelated memory-limit edit the user happens to have in the form.
  async function removePassword() {
    setPendingRemoval(true);
    try {
      await updateRedisSettings({
        maxmemory: redis?.maxmemory ?? "0",
        maxmemory_policy: redis?.maxmemory_policy ?? "noeviction",
        remove_password: true,
      });
      toast.success(t("redis.removed"));
      setRemoving(false);
      router.refresh();
    } catch (error) {
      toast.error(apiMessage(error, t("redis.saved")));
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

      // 202: the password is applied AFTER this response, because the
      // credential the panel is using is the one being replaced. Saying
      // "Saved" and refreshing here reported success for something still in
      // flight and re-read state that had not changed yet — which is exactly
      // what "the password was not updated" looks like.
      if (response?.status === 202) {
        form.reset({ ...values, password: "" });
        setApplying(true);
        return;
      }

      toast.success(t("redis.saved"));
      form.reset({ ...values, password: "" });
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Form {...form}>
        <form onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
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
            {/* Said where the change was made, and it stays until the reader
                dismisses it. A toast would have faded well before the change
                landed, which is how this looked like nothing happened. */}
            {applying ? (
              <div
                role="status"
                className="mt-3.5 flex flex-wrap items-start justify-between gap-3 rounded-lg border border-warning/40 bg-warning/10 px-3.5 py-2.5 text-sm"
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
              <p className="mt-3.5 flex items-center gap-2 rounded-lg border border-destructive/30 bg-destructive/5 px-3.5 py-2.5 text-sm text-destructive">
                <CircleAlert className="size-4 shrink-0" />
                {t("redis.notRunning")}
              </p>
            ) : null}
  
            <FormField
              control={form.control}
              name="maxmemory"
              render={({ field }) => (
                <Row
                  label={t("redis.maxmemory")}
                  required
                  hint={
                    redis?.memory_used_human
                      ? (redis.maxmemory && redis.maxmemory !== "0"
                          ? t("redis.usageOfLimit", { used: redis.memory_used_human, limit: redis.maxmemory })
                          : t("redis.usage", { used: redis.memory_used_human }))
                      : t("redis.maxmemoryHint")
                  }
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
                      <SelectTrigger className="w-full font-mono">
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    <SelectContent>
                      {REDIS_POLICIES.map((policy) => (
                        <SelectItem
                          key={policy}
                          value={policy}
                          className="font-mono"
                        >
                          {policy}
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
                  label={t("redis.password")}
                  hint={
                    redis?.password_manageable === false
                      ? t("redis.passwordLocked")
                      : t("redis.passwordHint")
                  }
                  error={validationMessage(
                    tv,
                    form.formState.errors.password?.message,
                  )}
                >
                  <div className="space-y-1.5">
                    <FormControl>
                      <PasswordInput
                        autoComplete="new-password"
                        placeholder={
                          redis?.has_password
                            ? t("redis.passwordSet")
                            : t("redis.passwordNone")
                        }
                        disabled={passwordLocked}
              disabledReason={
                !canManage
                  ? t("noPermission")
                  : t("passwordNotManageable")
              }
                        {...field}
                      />
                    </FormControl>
  
                    {/* Setting a password was possible; clearing one was not, so
                        a password could only ever be replaced. The API has taken
                        `remove_password` all along. Its own action rather than a
                        magic empty value — the API needs the distinction too,
                        because Laravel rewrites "" to null before validation. */}
                    {redis?.has_password && !passwordLocked ? (
                      <Button
                        type="button"
                        variant="ghost"
                        size="sm"
                        className="h-7 px-2 text-destructive hover:bg-destructive/10 hover:text-destructive"
                        onClick={() => setRemoving(true)}
                      >
                        {t("redis.removePassword")}
                      </Button>
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
