"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { useRefresh } from "@/hooks/use-refresh";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { KeyRound, ShieldAlert, TriangleAlert } from "lucide-react";
import { securityFormSchema, ROOT_LOGIN_OPTIONS } from "@/lib/schemas/settings";
import { updateSecuritySettings } from "@/lib/api/settings";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { validationMessage } from "@/lib/settings/validation-message";
import { Input } from "@/components/ui/input";
import { Form, FormField, FormControl } from "@/components/ui/form";
import { ChoiceField } from "@/components/ui/choice-field";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { isSevereSshChange } from "@/lib/settings/ssh-risks";
import {
  Row,
  Section,
  SectionActions,
} from "@/components/settings/setting-row";

export function SshForm({
  security,
  canManage,
  changedBy,
}) {
  const t = useTranslations("settings.security");
  const tv = useTranslations("settings.validation");
  const { refreshAndWait } = useRefresh();
  const [pendingValues, setPendingValues] = useState(null);
  // The confirmed save runs outside handleSubmit (the dialog resolved it), so
  // react-hook-form's own isSubmitting is already false by then.
  const [saving, setSaving] = useState(false);

  const defaults = {
    port: security?.port ?? 22,
    permit_root_login: security?.permit_root_login ?? "prohibit-password",
    password_authentication: security?.password_authentication ?? true,
  };

  const form = useForm({
    resolver: zodResolver(securityFormSchema),
    mode: "onBlur",
    // The port is held as text: the resolver coerces it for the API, and a
    // numeric default reads as "changed" the moment someone retypes it.
    defaultValues: { ...defaults, port: String(defaults.port) },
  });

  function consequencesOf(values) {
    const risks = [];
    if (Number(values.port) !== defaults.port) risks.push("port");
    if (!values.password_authentication && defaults.password_authentication) {
      risks.push("passwordOff");
    }
    if (
      values.permit_root_login === "no" &&
      defaults.permit_root_login !== "no"
    ) {
      risks.push("rootOff");
    }
    if (
      values.permit_root_login === "yes" &&
      defaults.permit_root_login !== "yes"
    ) {
      risks.push("rootPassword");
    }
    // Any save, even an unrelated one: the server says whether it will start enforcing.
    if (security?.save_enforces_ssh_access) risks.push("enforcesAccess");
    return risks;
  }

  async function save(values) {
    setSaving(true);
    try {
      await updateSecuritySettings(values);
      form.reset({ ...values, port: String(values.port) });
      await refreshAndWait();
      setPendingValues(null);
      toast.success(t("saved"));
    } catch (error) {
      // Close the dialog so a 422 (e.g. "no SSH key present") is readable on the
      // field it belongs to rather than behind an overlay.
      setPendingValues(null);
      handleValidationError(error, form, { fallback: t("saveFailed") });
    } finally {
      setSaving(false);
    }
  }

  function onSubmit(values) {
    if (consequencesOf(values).length) {
      setPendingValues(values);
      return;
    }
    return save(values);
  }

  const risks = pendingValues ? consequencesOf(pendingValues) : [];
  const submitting = saving || form.formState.isSubmitting;

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <Form {...form}>
        <form noValidate onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
          <Section
            icon={KeyRound}
            title={t("title")}
            description={t("description")}
            // Three questions, three columns: in two, the port sat alone under an
            // empty half (Krishna, 8 Oct).
            gridClassName="@4xl/section:grid-cols-3"
            readOnly={!canManage}
            changedBy={changedBy}
            actions={
              <SectionActions
                label={t("signIn.save")}
                isDirty={form.formState.isDirty}
                pending={submitting}
                onDiscard={() =>
                  form.reset({ ...defaults, port: String(defaults.port) })
                }
                canManage={canManage}
              />
            }
          >
            {/* Only when the SAVED config is dangerous; names the fix. */}
            {security?.permit_root_login === "yes" ? (
              <div className="flex gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                <p>{t("rootPasswordWarning")}</p>
              </div>
            ) : null}
  
            {/* Side by side: each choice has its consequence under it, and half the card is room enough. */}
            <FormField
              control={form.control}
              name="password_authentication"
              render={({ field }) => (
                <Row
                  label={t("signIn.label")}
                  hint={t("signIn.hint")}
                  error={validationMessage(
                    tv,
                    form.formState.errors.password_authentication?.message,
                  )}
                >
                  <ChoiceField
                    value={field.value ? "password" : "key"}
                    onChange={(next) => field.onChange(next === "password")}
                    disabled={!canManage}
                    options={[
                      {
                        value: "password",
                        label: t("signIn.option.password.label"),
                        hint: t("signIn.option.password.hint"),
                      },
                      {
                        value: "key",
                        label: t("signIn.option.key.label"),
                        hint: t("signIn.option.key.hint"),
                        // The API 422s this without a key (lockout guard). Keyed on the SAVED setting, not
                        // `field.value`, or the option disables itself once another radio is picked.
                        disabledReason:
                          security?.has_ssh_key === false &&
                          defaults.password_authentication
                            ? t("signIn.option.key.noKey")
                            : null,
                      },
                    ]}
                  />
                </Row>
              )}
            />
            <FormField
              control={form.control}
              name="permit_root_login"
              render={({ field }) => (
                <Row
                  label={t("rootLogin.label")}
                  hint={t("rootLogin.hint")}
                >
                  <ChoiceField
                    value={field.value}
                    onChange={field.onChange}
                    disabled={!canManage}
                    options={ROOT_LOGIN_OPTIONS.map((option) => ({
                      value: option,
                      label: t(`rootLogin.option.${option}.label`),
                      hint: t(`rootLogin.option.${option}.hint`),
                      tone: option === "yes" ? "warning" : undefined,
                    }))}
                  />
                </Row>
              )}
            />
  
            <FormField
              control={form.control}
              name="port"
              render={({ field }) => (
                <Row
                  label={t("port.label")}
                  required
                  hint={t("port.hint")}
                  error={validationMessage(
                    tv,
                    form.formState.errors.port?.message,
                  )}
                >
                  <FormControl>
                    <Input
                      placeholder="22"
                      className="font-mono"
                      inputMode="numeric"
                      autoComplete="off"
                      disabled={!canManage}
                      {...field}
                    />
                  </FormControl>
                </Row>
              )}
            />
          </Section>
        </form>
  
        <ConfirmDialog
          open={pendingValues !== null}
          onOpenChange={(open) => !open && setPendingValues(null)}
          icon={ShieldAlert}
          tone="warning"
          // Matches the worst consequence in the list.
          confirmVariant={isSevereSshChange(risks) ? "destructive" : "default"}
          title={t("confirm.title")}
          cancelLabel={t("confirm.cancel")}
          confirmLabel={t("confirm.submit")}
          pending={submitting}
          onConfirm={() => save(pendingValues)}
        >
          <ul className="space-y-2 text-sm">
            {risks.map((risk) => (
              <li key={risk} className="flex gap-2">
                <span aria-hidden className="text-muted-foreground">
                  •
                </span>
                <span>
                  {risk === "port"
                    ? t("confirm.port", {
                        from: defaults.port,
                        to: pendingValues.port,
                      })
                    : t(`confirm.${risk}`)}
                </span>
              </li>
            ))}
          </ul>
  
          <p className="text-sm text-muted-foreground">
            {t("confirm.testFirst")}
          </p>
        </ConfirmDialog>
      </Form>
    </DisabledReasonProvider>
  );
}
