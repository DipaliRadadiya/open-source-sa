"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Check, Circle } from "lucide-react";
import { cn } from "@/lib/utils";
import { changePasswordSchema } from "@/lib/schemas/account";
import { changePassword } from "@/lib/auth/auth-actions";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
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

  const rules = (value = "") => [
    { key: "reqLength", ok: value.length >= 10 },
    { key: "reqCase", ok: /[a-z]/.test(value) && /[A-Z]/.test(value) },
    { key: "reqNumber", ok: /[0-9]/.test(value) },
  ];

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
                  <FormMessage />
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
                    <ul className="space-y-1 pt-0.5">
                      {rules(field.value).map((r) => (
                        <li
                          key={r.key}
                          className={cn(
                            "flex items-center gap-1.5 text-xs",
                            r.ok ? "text-foreground" : "text-muted-foreground",
                          )}
                        >
                          {r.ok ? (
                            <Check className="size-3.5 text-success" />
                          ) : (
                            <Circle className="size-3.5 text-muted-foreground/50" />
                          )}
                          {t(`password.${r.key}`)}
                        </li>
                      ))}
                    </ul>
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
                        autoComplete="new-password"
                        placeholder={t("password.confirmPlaceholder")}
                        {...field}
                      />
                    </FormControl>
                    <FormMessage />
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
