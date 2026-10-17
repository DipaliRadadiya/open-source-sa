import { useEffect } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { updateProfileSchema } from "@/lib/schemas/account";
import { updateProfile } from "@/lib/auth/auth-actions";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { UserRound } from "lucide-react";
import { Input } from "@/components/ui/input";
import { Section, Row, SectionActions } from "@/components/settings/setting-row";
import { Form, FormField, FormControl } from "@/components/ui/form";

export function ProfileForm({ user, onDirtyChange }) {
  const t = useTranslations("account");
  const { refreshAndWait } = useRefresh();

  const form = useForm({
    resolver: zodResolver(updateProfileSchema),
    mode: "onBlur",
    defaultValues: { name: user?.name ?? "", username: user?.username ?? "" },
  });

  async function onSubmit(values) {
    try {
      const updated = await updateProfile(values);
      form.reset({ name: updated?.name, username: updated?.username });
      await refreshAndWait();
      toast.success(t("profile.success"));
    } catch (error) {
      handleValidationError(error, form, { fallback: t("profile.failed") });
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
      <form noValidate onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}>
        {/* The Settings card: icon head, two columns, a named Save in its own band.
            SectionActions also guards the sidebar, header links and reload. */}
        <Section
          icon={UserRound}
          title={t("profile.title")}
          description={t("profile.description")}
          actions={
            <SectionActions
              label={t("profile.submit")}
              isDirty={isDirty}
              pending={isSubmitting}
              onDiscard={() => form.reset()}
              canManage
            />
          }
        >
          <FormField
            control={form.control}
            name="name"
            render={({ field }) => (
              <Row label={t("profile.name")} hint={t("profile.nameHint")} required>
                <FormControl>
                  <Input autoComplete="name" placeholder={t("profile.namePlaceholder")} {...field} />
                </FormControl>
              </Row>
            )}
          />
          <FormField
            control={form.control}
            name="username"
            render={({ field }) => (
              <Row label={t("profile.username")} hint={t("profile.usernameHint")} required>
                <FormControl>
                  <Input autoComplete="username" placeholder={t("profile.usernamePlaceholder")} className="font-mono" {...field} />
                </FormControl>
              </Row>
            )}
          />
        </Section>
      </form>
    </Form>
  );
}
