"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { KeyRound, ShieldAlert, TriangleAlert } from "lucide-react";
import { securityFormSchema, ROOT_LOGIN_OPTIONS } from "@/lib/schemas/settings";
import { updateSecuritySettings } from "@/lib/api/settings";
import { updateFirewallRule } from "@/lib/api/firewall";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { validationMessage } from "@/lib/settings/validation-message";
import { Input } from "@/components/ui/input";
import { Checkbox } from "@/components/ui/checkbox";
import { Label } from "@/components/ui/label";
import { Form, FormField, FormControl } from "@/components/ui/form";
import { ChoiceField } from "@/components/ui/choice-field";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Row,
  Section,
  SectionActions,
} from "@/components/settings/setting-row";

/**
 * The two fields here that can end an operator's access to their own server —
 * the SSH port and password authentication — are confirmed before they're sent,
 * and the dialog lists what will actually happen rather than asking "are you
 * sure?".
 *
 * `oldPortRule` is the firewall rule holding the CURRENT port open. Move the
 * port and it guards a port nothing listens on, so the dialog offers to switch
 * it off — disabled, not deleted, because deleting a system-seeded rule is
 * refused and because the user may want it back if the new port turns out to be
 * unreachable.
 */
export function SshForm({
  security,
  canManage,
  oldPortRule,
  canManageFirewall,
  changedBy,
}) {
  const t = useTranslations("settings.security");
  const tv = useTranslations("settings.validation");
  const router = useRouter();
  const [pendingValues, setPendingValues] = useState(null);
  // The confirmed save runs outside handleSubmit (the dialog resolved it), so
  // react-hook-form's own isSubmitting is already false by then.
  const [saving, setSaving] = useState(false);
  // Offered, and ticked, only when there is actually a rule to close and the
  // user is allowed to touch the firewall.
  const canCloseOldPort = Boolean(oldPortRule && canManageFirewall);
  const [closeOldPort, setCloseOldPort] = useState(true);

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
    return risks;
  }

  async function save(values) {
    setSaving(true);
    try {
      await updateSecuritySettings(values);
      toast.success(t("saved"));

      // Strictly after the port change, and only then: closing the old port
      // first would leave a window with neither port reachable. A failure here
      // is reported on its own — the SSH change itself already worked.
      if (
        canCloseOldPort &&
        closeOldPort &&
        Number(values.port) !== defaults.port
      ) {
        try {
          await updateFirewallRule(oldPortRule.id, { enabled: false });
          toast.success(t("confirm.oldPortClosed", { port: oldPortRule.port }));
        } catch {
          toast.error(
            t("confirm.oldPortCloseFailed", { port: oldPortRule.port }),
          );
        }
      }
      form.reset({ ...values, port: String(values.port) });
      setPendingValues(null);
      router.refresh();
    } catch (error) {
      // Close the dialog so a 422 (e.g. "no SSH key present") is readable on the
      // field it belongs to rather than behind an overlay.
      setPendingValues(null);
      handleValidationError(error, form);
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
        <form onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
          <Section
            icon={KeyRound}
            title={t("title")}
            description={t("description")}
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
            {/* Only shown when the SAVED config is actually dangerous, and it names
                the fix. No summary of the settings themselves — they are the three
                rows directly below it. */}
            {security?.permit_root_login === "yes" ? (
              <div className="mt-3.5 flex max-w-2xl gap-3 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
                <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
                <p>{t("rootPasswordWarning")}</p>
              </div>
            ) : null}
  
            {/* Each choice is a sentence with its own consequence under it, so
                these two take the row rather than an 11rem control column. */}
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
  
                  wide
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
                        // The API refuses this with a 422 when no key is present
                        // (its lockout guard). Blocking it here turns a rejection
                        // you discover after confirming into a precondition you
                        // can read before choosing — and names the fix.
                        disabledReason:
                          security?.has_ssh_key === false && field.value
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
  
                  wide
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
            {risks.includes("port") && oldPortRule && !canManageFirewall ? (
              <li className="flex gap-2">
                <span aria-hidden className="text-muted-foreground">
                  •
                </span>
                <span>
                  {t("confirm.oldPortStaysOpen", { port: oldPortRule.port })}
                </span>
              </li>
            ) : null}
          </ul>
  
          {risks.includes("port") && canCloseOldPort ? (
            <div className="flex items-start gap-3 rounded-lg border p-3">
              <Checkbox
                id="close-old-port"
                checked={closeOldPort}
                onCheckedChange={(next) => setCloseOldPort(next === true)}
                className="mt-0.5"
              />
              <div className="space-y-1">
                <Label htmlFor="close-old-port" className="text-sm font-medium">
                  {t("confirm.closeOldPort", { port: oldPortRule.port })}
                </Label>
                <p className="text-xs text-muted-foreground">
                  {t("confirm.closeOldPortHint")}
                </p>
              </div>
            </div>
          ) : null}
          <p className="text-sm text-muted-foreground">
            {t("confirm.testFirst")}
          </p>
        </ConfirmDialog>
      </Form>
    </DisabledReasonProvider>
  );
}
