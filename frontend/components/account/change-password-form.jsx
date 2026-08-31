"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { changePasswordSchema } from "@/lib/schemas/account";
import { changePassword } from "@/lib/auth/auth-actions";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
import { PasswordRules } from "@/components/ui/password-rules";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
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
      <form
        method="post"
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        className="max-w-3xl space-y-6"
      >
        <Card>
          <CardHeader>
            <CardTitle>{t("password.title")}</CardTitle>
            <CardDescription>{t("password.description")}</CardDescription>
          </CardHeader>
          <CardContent className="space-y-5">
            <FormField
              control={form.control}
              name="current_password"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("password.current")}</FormLabel>
                  <FormControl>
                    <PasswordInput
                      autoComplete="current-password"
                      placeholder={t("password.currentPlaceholder")}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage field={t('password.current')} />
                </FormItem>
              )}
            />
            <div className="grid items-start gap-5 sm:grid-cols-2">
              <FormField
                control={form.control}
                name="password"
                render={({ field }) => (
                  <FormItem>
                    <FormLabel required>{t("password.new")}</FormLabel>
                    <FormControl>
                      <PasswordInput
                        autoComplete="new-password"
                        placeholder={t("password.newPlaceholder")}
                        {...field}
                      />
                    </FormControl>
                    <PasswordRules value={field.value} />
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
                        autoComplete="new-password"
                        placeholder={t("password.confirmPlaceholder")}
                        {...field}
                      />
                    </FormControl>
                    <FormMessage field={t('password.confirm')} />
                  </FormItem>
                )}
              />
            </div>
          </CardContent>
        </Card>

        <div className="flex justify-end">
          <Button type="submit" disabled={isSubmitting}>
            {isSubmitting && <Loader2 className="size-4 animate-spin" />}
            {isSubmitting ? t("password.saving") : t("password.submit")}
          </Button>
        </div>
      </form>
    </Form>
  );
}
