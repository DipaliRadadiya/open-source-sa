"use client";

import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2 } from "lucide-react";
import { updateProfileSchema } from "@/lib/schemas/account";
import { updateProfile } from "@/lib/auth/auth-actions";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { Input } from "@/components/ui/input";
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
  FormDescription,
  FormMessage,
} from "@/components/ui/form";

export function ProfileForm({ user, onDirtyChange }) {
  const t = useTranslations("account");
  const tc = useTranslations("common");
  const router = useRouter();

  const form = useForm({
    resolver: zodResolver(updateProfileSchema),
    mode: "onBlur",
    defaultValues: { name: user?.name ?? "", username: user?.username ?? "" },
  });

  async function onSubmit(values) {
    try {
      const updated = await updateProfile(values);
      toast.success(t("profile.success"));
      form.reset({ name: updated?.name, username: updated?.username });
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;
  const isDirty = form.formState.isDirty;

  // Report dirty state up so the tab switcher can confirm before discarding.
  useEffect(() => {
    onDirtyChange?.(isDirty);
    return () => onDirtyChange?.(false);
  }, [isDirty, onDirtyChange]);

  return (
    <Form {...form}>
      <form
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        className="max-w-3xl space-y-6"
      >
        <Card>
          <CardHeader>
            <CardTitle>{t("profile.title")}</CardTitle>
            <CardDescription>{t("profile.description")}</CardDescription>
          </CardHeader>
          <CardContent className="grid items-start gap-5 sm:grid-cols-2">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("profile.name")}</FormLabel>
                  <FormControl>
                    <Input
                      autoComplete="name"
                      placeholder={t("profile.namePlaceholder")}
                      {...field}
                    />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="username"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("profile.username")}</FormLabel>
                  <FormControl>
                    <Input
                      autoComplete="username"
                      placeholder={t("profile.usernamePlaceholder")}
                      className="font-mono"
                      {...field}
                    />
                  </FormControl>
                  <FormDescription>{t("profile.usernameHint")}</FormDescription>
                  <FormMessage />
                </FormItem>
              )}
            />
          </CardContent>
        </Card>

        <div className="flex justify-end">
          {/* A disabled Save explains nothing by itself, and "no changes yet"
              is the reason nobody guesses — they look for the broken field. */}
          <ReasonTooltip reason={!isDirty && !isSubmitting ? tc("nothingToSave") : null}>
            <Button type="submit" disabled={isSubmitting || !isDirty}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("profile.saving") : t("profile.submit")}
            </Button>
          </ReasonTooltip>
        </div>
      </form>
    </Form>
  );
}
