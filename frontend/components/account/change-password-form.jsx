"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { changePasswordSchema } from "@/lib/schemas/account";
import { changePassword } from "@/lib/auth/auth-actions";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";

export function ChangePasswordForm() {
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
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  return (
    <Form {...form}>
      <form onSubmit={form.handleSubmit(onSubmit)} className="grid gap-4 sm:max-w-md">
        <FormField
          control={form.control}
          name="current_password"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t("password.current")}</FormLabel>
              <FormControl>
                <PasswordInput autoComplete="current-password" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="password"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t("password.new")}</FormLabel>
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
              <FormLabel>{t("password.confirm")}</FormLabel>
              <FormControl>
                <PasswordInput autoComplete="new-password" {...field} />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <div>
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting ? t("password.saving") : t("password.submit")}
          </Button>
        </div>
      </form>
    </Form>
  );
}
