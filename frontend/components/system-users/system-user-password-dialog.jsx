"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, KeyRound } from "lucide-react";
import { systemUserPasswordSchema } from "@/lib/schemas/system-user";
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

export function SystemUserPasswordDialog({ user, open, onOpenChange }) {
  const t = useTranslations("systemUsers");
  const router = useRouter();

  const form = useForm({
    resolver: zodResolver(systemUserPasswordSchema),
    defaultValues: { password: "", password_confirmation: "" },
  });

  async function onSubmit(values) {
    try {
      await setSystemUserPassword(user.id, values);
      toast.success(t("toast.passwordSet"));
      onOpenChange?.(false);
      form.reset();
      router.refresh();
    } catch (error) {
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
              <FormItem>
                <FormLabel required>{t("password.new")}</FormLabel>
                <FormControl>
                  <PasswordInput
                    placeholder={t("password.newPlaceholder")}
                    autoComplete="new-password"
                    {...field}
                  />
                </FormControl>
                <FormMessage />
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
                <FormMessage />
              </FormItem>
            )}
          />
        </div>
      </FormModal>
    </Form>
  );
}
