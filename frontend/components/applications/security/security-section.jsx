"use client";

import { useEffect, useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Lock, Sparkles, TriangleAlert, ExternalLink, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { securityFormSchema } from "@/lib/schemas/application";
import { updateApplicationSecurity } from "@/lib/api/applications";
import { generatePassword } from "@/lib/applications/generate-password";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Switch } from "@/components/ui/switch";
import { PasswordInput } from "@/components/ui/password-input";
import { CopyButton } from "@/components/ui/copy-button";
import { Card, CardContent } from "@/components/ui/card";
import { Collapsible, CollapsibleContent } from "@/components/ui/collapsible";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Form, FormField, FormItem, FormLabel, FormControl, FormMessage } from "@/components/ui/form";

/**
 * Whole-site Basic Auth — one shared credential, not a directory/user table
 * (cPanel/Plesk's model doesn't match this API; CloudPanel/GridPane's
 * single-toggle shape does). The API always takes username+password together
 * whenever `enabled` is true — there is no "just change the password" call —
 * so re-saving a password means re-typing the username too, even unchanged.
 *
 * Purpose-built layout rather than the shared settings Section/Row grid:
 * that grid is right for a list of compact toggles/selects, but a text-heavy
 * on/off-plus-credentials card reads as sparse and cramped inside it. This is
 * the only screen of its kind so far — worth its own markup instead of
 * bending a pattern built for something else.
 */
export function SecuritySection({ appId, application, domain, canManage }) {
  const t = useTranslations("applications.security");
  const router = useRouter();
  // The password the API just accepted, shown once for copying — it is never
  // sent back on any read, so this is the only chance to grab it again.
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

  // Any edit after a save invalidates the "here's what you just set" panel —
  // it must not go on showing a password that no longer matches what's saved.
  useEffect(() => {
    // eslint-disable-next-line react-hooks/incompatible-library -- react-hook-form's watch() is a known false positive for the React Compiler lint
    const subscription = form.watch(() => setJustSaved(null));
    return () => subscription.unsubscribe();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  async function onSubmit(values) {
    try {
      const payload = values.enabled
        ? { enabled: true, username: values.username.trim(), password: values.password }
        : { enabled: false };
      await updateApplicationSecurity(appId, payload);
      toast.success(values.enabled ? t("enabledToast") : t("disabledToast"));
      setJustSaved(values.enabled ? { username: values.username.trim(), password: values.password } : null);
      form.reset({ enabled: values.enabled, username: values.enabled ? values.username.trim() : "", password: "" });
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  function discard() {
    form.reset(defaults);
    setJustSaved(null);
  }

  const submitting = form.formState.isSubmitting;
  const isDirty = form.formState.isDirty;
  const saveReason = !canManage ? t("noPermission") : !isDirty ? t("nothingToSave") : null;

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())} className="max-w-4xl">
        <Card className="gap-0 overflow-hidden py-0 shadow-sm">
          <CardContent className="space-y-5 p-5">
            {/* The toggle IS the feature — it gets a full-width tinted row of
                its own, with the status badge right on it, instead of being
                one bare line lost in a mostly-empty card. */}
            <FormField
              control={form.control}
              name="enabled"
              render={({ field }) => (
                <FormItem className="!mt-0">
                  {/* A real <label>, not a styled <div> — clicking anywhere in
                      the row (icon, text, badge) activates the nested Switch
                      exactly once via native label-forwarding, no click
                      handler or double-toggle logic needed. Precision-pointing
                      at the small switch itself stops being the only way in. */}
                  <label
                    className={cn(
                      "flex flex-row items-center justify-between gap-4 rounded-xl border p-4 transition-colors",
                      field.value ? "border-success/30 bg-success/5" : "bg-muted/40",
                      !canManage || submitting ? "cursor-not-allowed" : "cursor-pointer",
                    )}
                  >
                    <div className="flex items-start gap-3">
                      <span
                        className={cn(
                          "mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full",
                          field.value ? "bg-success/15 text-success" : "bg-muted-foreground/10 text-muted-foreground",
                        )}
                      >
                        <Lock className="size-4" />
                      </span>
                      <div className="space-y-1">
                        <div className="flex flex-wrap items-center gap-2">
                          <span className="font-medium">{t("enable")}</span>
                          <Badge variant={field.value ? "success" : "secondary"}>
                            {field.value ? t("statusProtected") : t("statusNotProtected")}
                          </Badge>
                        </div>
                        <p className="text-sm text-muted-foreground">
                          {field.value ? t("enableHint") : t("disabledHint")}
                        </p>
                      </div>
                    </div>
                    <div className="flex h-5 shrink-0 items-center">
                      <FormControl>
                        <Switch
                          checked={field.value}
                          onCheckedChange={field.onChange}
                          disabled={!canManage || submitting}
                          aria-label={t("enable")}
                        />
                      </FormControl>
                    </div>
                  </label>
                </FormItem>
              )}
            />

            {/* Framed as guidance, not just a warning — answers "should I turn
                this on" before anyone has to guess from the toggle alone. */}
            <Collapsible open={!enabled}>
              <CollapsibleContent className="overflow-hidden data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                <div className="rounded-lg bg-muted/40 p-3.5 text-sm">
                  <p className="mb-1 font-medium">{t("whenToUseTitle")}</p>
                  <p className="text-muted-foreground">{t("whenToUseBody")}</p>
                </div>
              </CollapsibleContent>
            </Collapsible>

            <Collapsible open={enabled}>
              {/* `overflow-hidden` here is what makes the height animation
                  work, but it also clips anything that visually bleeds past
                  the edge — an invalid field's ring included, right where
                  the username/password inputs sit flush against this
                  boundary. `-mx-1 px-1` gives the ring 4px to bleed into on
                  each side while netting zero actual layout shift. */}
              <CollapsibleContent className="-mx-1 overflow-hidden px-1 data-[state=closed]:animate-collapsible-up data-[state=open]:animate-collapsible-down">
                <div className="space-y-4 border-t pt-5">
                  <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                    <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                    <p>{t("warning")}</p>
                  </div>

                  {/* What these fields actually are, before anyone fills them
                      in — without this, "Username" reads as if it might be
                      the visitor's ServerAvatar login, and the plain browser
                      popup it produces can look like the feature is broken. */}
                  <p className="text-sm text-muted-foreground">{t("credentialsNote")}</p>

                  {/* Side by side, same column width each — username alone at
                      full width next to a half-width password (squeezed by its
                      Generate button) read as an unintentional size mismatch
                      rather than a deliberate layout. */}
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
                        // Generate sits visually top-right next to the label,
                        // but comes AFTER the password input in the actual
                        // markup (positioned, not reordered) — so Tab reaches
                        // the field itself first and the auxiliary action
                        // second, instead of a keyboard user hitting "Generate"
                        // before they've even reached the field it fills.
                        <FormItem className="relative">
                          <FormLabel required>{t("password")}</FormLabel>
                          <FormControl>
                            <PasswordInput
                              autoComplete="new-password"
                              placeholder={t("passwordPlaceholder")}
                              disabled={!canManage || submitting}
                              {...field}
                            />
                          </FormControl>
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

                  {/* Explains both fields together — it belongs under the pair,
                      not tucked under just one of them. */}
                  <p className="text-xs text-muted-foreground">{t("passwordHint")}</p>

                  {justSaved ? (
                    <div className="space-y-2 rounded-lg border bg-muted/40 p-3">
                      <p className="text-xs font-medium text-muted-foreground">{t("savedCredentials")}</p>
                      {/* Each value is named. Two bare code blocks stacked
                          together are ambiguous — a generated password looks
                          random, but so can a username, so "which one is
                          which" was left to guesswork at exactly the moment
                          the password is shown for the only time. */}
                      <div className="grid gap-1.5 text-xs">
                        {[
                          { label: t("username"), value: justSaved.username },
                          { label: t("password"), value: justSaved.password },
                        ].map((item) => (
                          <div key={item.label} className="flex items-center gap-2">
                            <span className="w-20 shrink-0 text-muted-foreground">{item.label}</span>
                            <code className="min-w-0 flex-1 truncate rounded border bg-background px-2 py-1 font-mono">
                              {item.value}
                            </code>
                            <CopyButton value={item.value} label={item.label} />
                          </div>
                        ))}
                      </div>
                      <Button asChild variant="outline" size="sm">
                        <a href={`https://${domain}`} target="_blank" rel="noreferrer">
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

          {/* Directly under the content it saves — no gap wide enough to
              read as a separate, unrelated strip. */}
          <div className="flex items-center justify-end gap-2 border-t bg-muted/20 px-5 py-3">
            {isDirty ? (
              <Button type="button" variant="ghost" onClick={discard} disabled={submitting}>
                {t("discard")}
              </Button>
            ) : null}
            <ReasonTooltip reason={saveReason}>
              <Button type="submit" disabled={Boolean(saveReason) || submitting}>
                {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
                {submitting ? t("saving") : t("save")}
              </Button>
            </ReasonTooltip>
          </div>
        </Card>
      </form>
    </Form>
  );
}
