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
import { Note } from "@/components/ui/note";
import Link from "@/components/ui/app-link";
import { useRefresh } from "@/hooks/use-refresh";

export function SystemUserPasswordDialog({
  user,
  open,
  onOpenChange,
  sshPasswordOff = false,
  canOpenSecurity = false,
}) {
  const t = useTranslations("systemUsers");
  const { refresh, refreshThen } = useRefresh();

  const form = useForm({
    resolver: zodResolver(systemUserPasswordSchema),
    defaultValues: { password: "", password_confirmation: "" },
  });

  async function onSubmit(values) {
    try {
      await setSystemUserPassword(user.id, values);
      // Saving… holds until the list has the new value, so reopening never shows the
      // old one.
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
      handleValidationError(error, form, { fallback: t("toast.passwordFailed") });
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  // Clear values and errors on close: the form stays mounted, so stale errors would
  // show on reopen.
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
        // Focus the new password, not the reveal button, whose tooltip would swallow the
        // first Escape.
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
        {/* "Server login password" overpromises when sshd refuses passwords. */}
        {sshPasswordOff ? (
          <Note>
            <p>{t("password.sshSignInOff")}</p>
            {canOpenSecurity ? (
              <Link href="/settings/security" prefetch={false} className="text-primary underline-offset-4 hover:underline">
                {t("sshNotEnforced.action")}
              </Link>
            ) : null}
          </Note>
        ) : null}

        <div className="space-y-1.5 rounded-lg border bg-muted/30 p-3">
          <p className="text-xs font-medium text-muted-foreground">
            {t("password.current")}
          </p>
          <PasswordReveal password={user?.password} />
        </div>

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
                {/* Same control as the create dialog; fills both fields. */}
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
