import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { changePasswordSchema } from "@/lib/schemas/account";
import { changePassword } from "@/lib/auth/auth-actions";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { KeyRound } from "lucide-react";
import { PasswordInput } from "@/components/ui/password-input";
import { PasswordRules } from "@/components/ui/password-rules";
import { Section, Row, SectionActions } from "@/components/settings/setting-row";
import { Form, FormField, FormControl } from "@/components/ui/form";

export function ChangePasswordForm({ lastChanged = null }) {
  const t = useTranslations("account");
  const form = useForm({
    resolver: zodResolver(changePasswordSchema),
    defaultValues: {
      current_password: "",
      password: "",
      password_confirmation: "",
    },
  });

  async function onSubmit(values) {
    try {
      await changePassword(values);
      toast.success(t("password.success"));
      form.reset();
    } catch (error) {
      handleValidationError(error, form, { fallback: t("password.failed") });
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  return (
    <Form {...form}>
      <form noValidate method="post" onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
        <Section
          icon={KeyRound}
          title={t("password.title")}
          description={t("password.description")}
          // Opposite the button, where Settings says who changed a card last.
          changedBy={lastChanged ? t("password.lastChanged", { when: lastChanged }) : null}
          actions={
            <SectionActions
              label={t("password.submit")}
              isDirty={form.formState.isDirty}
              pending={isSubmitting}
              onDiscard={() => form.reset()}
              canManage
            />
          }
        >
          <FormField
            control={form.control}
            name="current_password"
            render={({ field }) => (
              <Row label={t("password.current")} required>
                <FormControl>
                  <PasswordInput autoComplete="current-password" placeholder={t("password.currentPlaceholder")} {...field} />
                </FormControl>
              </Row>
            )}
          />
          {/* Current alone on its row, the new pair side by side below it. */}
          <FormField
            control={form.control}
            name="password"
            render={({ field }) => (
              <Row label={t("password.new")} required className="@3xl/section:col-start-1">
                <FormControl>
                  <PasswordInput autoComplete="new-password" placeholder={t("password.newPlaceholder")} {...field} />
                </FormControl>
                <PasswordRules value={field.value} />
              </Row>
            )}
          />
          <FormField
            control={form.control}
            name="password_confirmation"
            render={({ field }) => (
              <Row label={t("password.confirm")} required>
                <FormControl>
                  <PasswordInput autoComplete="new-password" placeholder={t("password.confirmPlaceholder")} {...field} />
                </FormControl>
              </Row>
            )}
          />
        </Section>
      </form>
    </Form>
  );
}
