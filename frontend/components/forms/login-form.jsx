"use client";

import { useTransition } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter, useSearchParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { loginSchema } from "@/lib/schemas/auth";
import { login } from "@/lib/auth/auth-actions";
import { safeNext } from "@/lib/auth/safe-next";
import { takeRememberedPath } from "@/lib/auth/last-path";
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

export function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const t = useTranslations("auth");
  // `router.push` cannot be awaited, so a transition keeps the button busy until navigation ends.
  const [navigating, startNavigation] = useTransition();
  const form = useForm({
    resolver: zodResolver(loginSchema),
    defaultValues: { username: "", password: "" },
  });

  async function onSubmit(values) {
    try {
      await login(values);
      startNavigation(() => {
        // Both sources are untrusted and go through `safeNext`. "/" lets app/page.js pick the role's landing page.
        router.push(safeNext(searchParams.get("next")) ?? takeRememberedPath() ?? "/");
        router.refresh();
      });
    } catch (error) {
      // The login throttle: shown on the form, not as the generic rate-limit toast.
      if (error?.response?.status === 429) {
        form.setError("password", { message: t("tooManyAttempts") });
        return;
      }
      handleValidationError(error, form, { fallback: t("signInFailed") });
    }
  }

  const isSubmitting = form.formState.isSubmitting || navigating;

  return (
    <Form {...form}>
      <form noValidate
        // Unhydrated, a form with no method submits via GET and leaks the password into the URL.
        method="post"
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        className="grid gap-4"
      >
        <FormField
          control={form.control}
          name="username"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("fields.username")}</FormLabel>
              <FormControl>
                <Input
                  placeholder={t("fields.usernamePlaceholder")}
                  autoComplete="username"
                  autoFocus
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
                  placeholder={t("fields.passwordPlaceholder")}
                  autoComplete="current-password"
                  {...field}
                />
              </FormControl>
              <FormMessage field={t('fields.password')} />
            </FormItem>
          )}
        />
        <Button
          type="submit"
          disabled={isSubmitting}
          className="mt-2 h-10 w-full shadow-sm transition-all hover:shadow"
        >
          {isSubmitting && <Loader2 className="size-4 animate-spin" />}
          {isSubmitting ? t("signingIn") : t("signIn")}
        </Button>
      </form>
    </Form>
  );
}
