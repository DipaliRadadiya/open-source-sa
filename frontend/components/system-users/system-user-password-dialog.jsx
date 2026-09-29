import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, KeyRound, Sparkles } from "lucide-react";
import { systemUserPasswordSchema } from "@/lib/schemas/system-user";
import { generatePassword } from "@/lib/applications/generate-password";
import { setSystemUserPassword } from "@/lib/api/system-users";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";
import { PasswordReveal } from "@/components/system-users/password-reveal";
import { useRefresh } from "@/hooks/use-refresh";

export function SystemUserPasswordDialog({ user, open, onOpenChange }) {
  const t = useTranslations("systemUsers");
  const { refresh, refreshThen } = useRefresh();

  const form = useForm({
    resolver: zodResolver(systemUserPasswordSchema),
    defaultValues: { password: "", password_confirmation: "" },
  });

  async function onSubmit(values) {
    try {
      await setSystemUserPassword(user.id, values);
      // Saving… holds until the list has the new value, so reopening straight
      // away never shows the old one.
      await new Promise((resolve) => refreshThen(resolve));
      toast.success(t("toast.passwordSet"));
      handleOpenChange(false);
    } catch (error) {
      if (error?.response?.status === 404) {
        toast.info(t("toast.alreadyGone", { username: user.username }));
        handleOpenChange(false);
        refresh();
        return;
      }
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  // Clear values + validation errors when the modal closes (it only hides —
  // the form stays mounted, so stale errors would show on reopen).
  function handleOpenChange(next) {
    if (!next) form.reset();
    onOpenChange?.(next);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        // The new password, not the eye on the current one: landing there
        // opened its tooltip, and the first Escape only closed that.
        initialFocus="input[name=password]"
        icon={KeyRound}
        title={`${t("password.title")} — ${user?.username ?? ""}`}
        description={t("password.subtitle", { username: user?.username ?? "" })}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => handleOpenChange(false)}
            >
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("saving") : t("password.submit")}
            </Button>
          </>
        }
      >
        {/* Current */}
        <div className="space-y-1.5 rounded-lg border bg-muted/30 p-3">
          <p className="text-xs font-medium text-muted-foreground">
            {t("password.current")}
          </p>
          <PasswordReveal password={user?.password} />
        </div>

        {/* Set new */}
        <div className="space-y-4 rounded-lg border p-3">
          <p className="text-sm font-medium">{t("password.setNew")}</p>
          <FormField
            control={form.control}
            name="password"
            render={({ field }) => (
              <FormItem className="relative">
                <FormLabel required>{t("password.new")}</FormLabel>
                <FormControl>
                  <PasswordInput
                    placeholder={t("password.newPlaceholder")}
                    autoComplete="new-password"
                    {...field}
                  />
                </FormControl>
                {/* Same control as the create dialog. Fills both fields —
                    nobody retypes a generated password to confirm it. */}
                <Button
                  type="button"
                  variant="link"
                  size="sm"
                  className="absolute top-0 right-0 h-auto p-0 text-xs"
                  onClick={() => {
                    const value = generatePassword();
                    form.setValue("password", value, { shouldDirty: true, shouldValidate: true });
                    form.setValue("password_confirmation", value, { shouldDirty: true, shouldValidate: true });
                  }}
                >
                  <Sparkles className="size-3" />
                  {t("create.generate")}
                </Button>
                <FormMessage field={t('password.new')} />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="password_confirmation"
            render={({ field }) => (
              <FormItem>
                <FormLabel required>{t("password.confirm")}</FormLabel>
                <FormControl>
                  <PasswordInput
                    placeholder={t("password.confirmPlaceholder")}
                    autoComplete="new-password"
                    {...field}
                  />
                </FormControl>
                <FormMessage field={t('password.confirm')} />
              </FormItem>
            )}
          />
        </div>
      </FormModal>
    </Form>
  );
}
