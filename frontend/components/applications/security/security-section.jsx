"use client";

import { useEffect, useState } from "react";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
import { CardSaveFooter } from "@/components/ui/card-save-footer";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { DENIED, DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import Link from "@/components/ui/app-link";
import { Eye, EyeOff, Lightbulb, Lock, Sparkles, TriangleAlert, ExternalLink } from "lucide-react";
import { cn } from "@/lib/utils";
import { Note } from "@/components/ui/note";
import { securityFormSchema } from "@/lib/schemas/application";
import { updateApplicationSecurity } from "@/lib/api/applications";
import { generatePassword } from "@/lib/applications/generate-password";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { isReloadFailed } from "@/lib/applications/reload-pending";
import { useReloadPending, useReloadPendingActions } from "@/components/applications/reload-pending-provider";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { useBranding } from "@/components/branding-provider";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { PasswordInput } from "@/components/ui/password-input";
import { CopyButton } from "@/components/ui/copy-button";
import { Card, CardContent } from "@/components/ui/card";
import { Collapsible, CollapsibleContent } from "@/components/ui/collapsible";
import { Form, FormField, FormItem, FormLabel, FormControl, FormMessage } from "@/components/ui/form";

export function SecuritySection({ appId, application, domain, canManage }) {
  const t = useTranslations("applications.security");
  const tPending = useTranslations("applications.reloadPending");
  const { name: brand } = useBranding();
  const { refreshAndWait } = useRefresh();
  const pending = useReloadPending(appId);
  const { mark, stillPending } = useReloadPendingActions();
  // Shown once: the API never returns the password on reads.
  const [justSaved, setJustSaved] = useState(null);

  const defaults = {
    enabled: application.basic_auth_enabled ?? false,
    username: application.basic_auth_username ?? "",
    password: "",
  };

  const form = useForm({
    resolver: zodResolver(securityFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  const enabled = useWatch({ control: form.control, name: "enabled" });
  const passwordValue = useWatch({ control: form.control, name: "password" });

  useEffect(() => {
    // eslint-disable-next-line react-hooks/incompatible-library -- react-hook-form's watch() is a known false positive for the React Compiler lint
    const subscription = form.watch((_values, { type }) => {
      if (type === "change") setJustSaved(null);
    });
    return () => subscription.unsubscribe();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  // New credentials stay in memory only, never in the cookie.
  function keepSaved(values) {
    setSavedProtected(values.enabled);
    setJustSaved(values.enabled ? { username: values.username.trim(), password: values.password } : null);
    form.reset({ enabled: values.enabled, username: values.enabled ? values.username.trim() : "", password: "" });
  }

  async function onSubmit(values) {
    try {
      const payload = values.enabled
        ? { enabled: true, username: values.username.trim(), password: values.password }
        : { enabled: false };
      await updateApplicationSecurity(appId, payload);
      keepSaved(values);
      await refreshAndWait();
      if (stillPending(appId)) toast.warning(tPending("stillPendingToast"));
      else toast.success(values.enabled ? t("enabledToast") : t("disabledToast"));
    } catch (error) {
      if (isReloadFailed(error)) {
        mark(appId, error);
        keepSaved(values);
        await refreshAndWait();
        toast.warning(tPending("toast"));
        return;
      }
      handleValidationError(error, form, { fallback: t("saveFailed") });
    }
  }

  function discard() {
    form.reset(defaults);
    setJustSaved(null);
  }

  const submitting = form.formState.isSubmitting;
  const isDirty = form.formState.isDirty;
  useWatchUnsaved("app-security", isDirty);

  // Apps that use the Authorization header can't take Basic Auth; the API refuses it.
  const conflicts = application.basic_auth_supported === false;
  const serverProtected = application.basic_auth_enabled ?? false;
  const unencrypted =
    application.basic_auth_unencrypted ||
    (enabled && Boolean(application.url?.startsWith("http://")));
  const [savedProtected, setSavedProtected] = useState(null);
  if (savedProtected !== null && savedProtected === serverProtected) setSavedProtected(null);
  const alreadyProtected = savedProtected ?? serverProtected;
  const cannotEnable = conflicts && !alreadyProtected;

  const controlReason = !canManage ? DENIED : cannotEnable ? t("unsupportedForType") : null;
  const saveReason = controlReason ?? (!isDirty ? t("nothingToSave") : null);

  return (
    <DisabledReasonProvider reason={controlReason}>
      <Form {...form}>
        <form noValidate method="post" onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())} >
          <Card className="gap-0 overflow-hidden py-0">
            <CardContent className="space-y-5 p-5">
              <FormField
                control={form.control}
                name="enabled"
                render={({ field }) => (
                  <FormItem className="!mt-0">
                    <label
                      className={cn(
                        "flex flex-col gap-3 rounded-xl border p-4 transition-colors sm:flex-row sm:items-center sm:justify-between sm:gap-4",
                        pending
                          ? "border-warning/30 bg-warning/5"
                          : alreadyProtected
                            ? "border-success/30 bg-success/5"
                            : "bg-muted/40",
                        !canManage || submitting ? "cursor-not-allowed" : "cursor-pointer",
                      )}
                    >
                      <div className="flex min-w-0 flex-1 items-start gap-3">
                        <span
                          className={cn(
                            "mt-0.5 hidden size-9 shrink-0 items-center justify-center rounded-full sm:flex",
                            pending
                              ? "bg-warning/15 text-warning"
                              : alreadyProtected
                                ? "bg-success/15 text-success"
                                : "bg-muted-foreground/10 text-muted-foreground",
                          )}
                        >
                          <Lock className="size-4" />
                        </span>
                        <div className="space-y-1">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="font-medium">{t("enable")}</span>
                            <Badge variant={pending ? "warning" : alreadyProtected ? "success" : "muted"}>
                              {pending
                                ? tPending("badge")
                                : alreadyProtected
                                  ? t("statusProtected")
                                  : t("statusNotProtected")}
                            </Badge>
                          </div>
                          <p className="text-sm text-muted-foreground">
                            {cannotEnable
                              ? t("unsupportedForType")
                              : pending
                                ? tPending("hint")
                                : alreadyProtected
                                  ? t("enableHint")
                                  : t("disabledHint")}
                          </p>
                        </div>
                      </div>
                      {canManage ? (
                        <div className="flex h-5 shrink-0 items-center">
                          <FormControl>
                            <Switch
                              checked={field.value}
                              onCheckedChange={field.onChange}
                              disabled={submitting || cannotEnable}
                              aria-label={t("enable")}
                            />
                          </FormControl>
                        </div>
                      ) : null}
                    </label>
                    <FormMessage />
                  </FormItem>
                )}
              />

              {unencrypted ? (
                <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                  <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                  <p>
                    {t.rich("unencrypted", {
                      link: (chunks) => (
                        <Link
                          href={`/applications/${appId}/domains?tab=ssl`}
                          prefetch={false}
                          className="font-medium underline underline-offset-2"
                        >
                          {chunks}
                        </Link>
                      ),
                    })}
                  </p>
                </div>
              ) : null}

              {conflicts && alreadyProtected ? (
                <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                  <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                  <p>{t("unsupportedProtected")}</p>
                </div>
              ) : null}
  
              <Collapsible open={!enabled}>
                <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                  <Note icon={Lightbulb} title={t("whenToUseTitle")}>
                    {t("whenToUseBody")}
                  </Note>
                </CollapsibleContent>
              </Collapsible>
  
              <Collapsible open={enabled}>
                <CollapsibleContent className="-mx-1 -mb-1 overflow-hidden px-1 pb-1 data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                  <div className="space-y-4 border-t pt-5">
                    <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                      <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                      <p>{t("warning")}</p>
                    </div>
  
                    <p className="text-sm text-muted-foreground">{t("credentialsNote", { brand })}</p>
  
                    <div className="grid gap-4 sm:grid-cols-2">
                      <FormField
                        control={form.control}
                        name="username"
                        render={({ field }) => (
                          <FormItem>
                            <FormLabel required>{t("username")}</FormLabel>
                            <FormControl>
                              <Input
                                autoComplete="off"
                                spellCheck={false}
                                placeholder={t("usernamePlaceholder")}
                                disabled={!canManage || submitting}
                                {...field}
                              />
                            </FormControl>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
  
                      <FormField
                        control={form.control}
                        name="password"
                        render={({ field }) => (
                          <FormItem className="relative">
                            <FormLabel required hint={t("passwordHint")}>{t("password")}</FormLabel>
                            <FormControl>
                              <PasswordInput
                                autoComplete="new-password"
                                placeholder={t("passwordPlaceholder")}
                                disabled={!canManage || submitting}
                                {...field}
                              />
                            </FormControl>
                            {alreadyProtected && isDirty && !passwordValue ? (
                              <p className="text-xs text-muted-foreground">{t("passwordAgainHint")}</p>
                            ) : null}
                            <Button
                              type="button"
                              variant="link"
                              size="sm"
                              className="absolute top-0 right-0 h-auto p-0 text-xs"
                              disabled={!canManage || submitting}
                              onClick={() =>
                                form.setValue("password", generatePassword(), {
                                  shouldDirty: true,
                                  shouldValidate: true,
                                })
                              }
                            >
                              <Sparkles className="size-3" />
                              {t("generate")}
                            </Button>
                            <FormMessage />
                          </FormItem>
                        )}
                      />
                    </div>
  
                    {justSaved ? (
                      <div className="space-y-2 rounded-lg border bg-muted/40 p-3">
                        <p className="text-xs font-medium text-muted-foreground">{t("savedCredentials")}</p>
                        <div className="grid gap-2 sm:grid-cols-2">
                          <SavedValue label={t("username")} value={justSaved.username} />
                          <SavedValue label={t("password")} value={justSaved.password} secret />
                        </div>
                        <Button asChild variant="outline" size="sm">
                          <a
                            href={application?.url ?? `https://${domain}`}
                            target="_blank"
                            rel="noreferrer"
                          >
                            <ExternalLink className="size-3.5" />
                            {t("openSite")}
                          </a>
                        </Button>
                      </div>
                    ) : null}
                  </div>
                </CollapsibleContent>
              </Collapsible>
            </CardContent>
  
            <CardSaveFooter
              submit
              saving={submitting}
              dirty={isDirty}
              saveReason={saveReason}
              onDiscard={discard}
              savingNote={t("savingNote")}
            />
          </Card>
        </form>
      </Form>
    </DisabledReasonProvider>
  );
}

function SavedValue({ label, value, secret = false }) {
  const t = useTranslations("applications.security");
  const [revealed, setRevealed] = useState(false);
  const hidden = secret && !revealed;

  return (
    <div className="min-w-0 space-y-1">
      <span className="block text-xs text-muted-foreground">{label}</span>
      <div className="flex items-center gap-1 rounded-lg border bg-background px-2 py-1">
        <code
          className={cn(
            "min-w-0 flex-1 truncate font-mono text-xs",
            // select-none so the mask dots can't be copied as the password.
            hidden && "select-none tracking-[0.2em] text-muted-foreground",
          )}
        >
          {hidden ? "••••••••••••" : value}
        </code>
        {secret ? (
          <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            className="size-7"
            onClick={() => setRevealed((shown) => !shown)}
            aria-pressed={revealed}
            aria-label={revealed ? t("hidePassword") : t("showPassword")}
          >
            {revealed ? <EyeOff className="size-3.5" /> : <Eye className="size-3.5" />}
          </Button>
        ) : null}
        <CopyButton value={value} label={label} />
      </div>
    </div>
  );
}
