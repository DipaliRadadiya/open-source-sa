"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { registerSchema } from "@/lib/schemas/auth";
import { register as registerUser } from "@/lib/auth/auth-actions";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";

export function RegisterForm() {
  const router = useRouter();
  const t = useTranslations("auth");
  const form = useForm({
    resolver: zodResolver(registerSchema),
    defaultValues: {
      name: "",
      username: "",
      password: "",
      password_confirmation: "",
    },
  });

  async function onSubmit(values) {
    try {
      await registerUser(values);
      // First admin lands on the setup checklist, not straight on the dashboard.
      router.push("/setup");
      router.refresh();
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
        className="grid gap-4"
      >
        <FormField
          control={form.control}
          name="name"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("fields.name")}</FormLabel>
              <FormControl>
                <Input
                  placeholder={t("fields.namePlaceholder")}
                  autoComplete="name"
                  autoFocus
                  {...field}
                />
              </FormControl>
              <FormMessage field={t('fields.name')} />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="username"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("fields.username")}</FormLabel>
              <FormControl>
                <Input
                  placeholder={t("fields.chooseUsernamePlaceholder")}
                  autoComplete="username"
                  {...field}
                />
              </FormControl>
              <FormMessage field={t('fields.username')} />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="password"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("fields.password")}</FormLabel>
              <FormControl>
                <PasswordInput
                  placeholder={t("fields.newPasswordPlaceholder")}
                  autoComplete="new-password"
                  {...field}
                />
              </FormControl>
              <FormMessage field={t('fields.password')} />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="password_confirmation"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("fields.confirmPassword")}</FormLabel>
              <FormControl>
                <PasswordInput
                  placeholder={t("fields.confirmPasswordPlaceholder")}
                  autoComplete="new-password"
                  {...field}
                />
              </FormControl>
              <FormMessage field={t('fields.confirmPassword')} />
            </FormItem>
          )}
        />
        <Button
          type="submit"
          disabled={isSubmitting}
          className="mt-2 h-10 w-full shadow-sm transition-all hover:shadow"
        >
          {isSubmitting && <Loader2 className="size-4 animate-spin" />}
          {isSubmitting ? t("creatingAccount") : t("createAccount")}
        </Button>
      </form>
    </Form>
  );
}
