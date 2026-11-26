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
import { getMyPermissions } from "@/lib/api/permissions";
import { landingPath } from "@/lib/permissions/landing-path";
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

async function landingFor() {
  try {
    return landingPath(await getMyPermissions());
  } catch {
    return "/";
  }
}

export function LoginForm() {
  const router = useRouter();
  const searchParams = useSearchParams();
  const t = useTranslations("auth");
  // router.push can't be awaited, so a transition keeps the button busy.
  const [navigating, startNavigation] = useTransition();
  const form = useForm({
    resolver: zodResolver(loginSchema),
    defaultValues: { username: "", password: "" },
  });

  async function onSubmit(values) {
    try {
      await login(values);
      const wanted = safeNext(searchParams.get("next")) ?? takeRememberedPath();
      const target = wanted && wanted !== "/" ? wanted : await landingFor();
      startNavigation(() => router.push(target));
    } catch (error) {
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
