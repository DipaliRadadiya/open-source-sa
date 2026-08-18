"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { CircleAlert, Database, KeyRound } from "lucide-react";
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

      await updateRedisSettings(payload);
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
