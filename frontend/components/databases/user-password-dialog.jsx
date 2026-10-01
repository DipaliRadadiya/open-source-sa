import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { KeyRound, Loader2, Sparkles } from "lucide-react";
import { passwordFormSchema } from "@/lib/schemas/database";
import { randomPassword } from "@/lib/databases/random";
import { updateUserPassword } from "@/lib/api/databases";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
import { CopyButton } from "@/components/ui/copy-button";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";

/**
 * Changing a password breaks every app still using the old one; the dialog
 * says so before the button.
 */
export function UserPasswordDialog({ database, user, open, onOpenChange }) {
  const t = useTranslations("databases.users");
  const { refreshAndWait } = useRefresh();
  // The new connection string, shown once the change lands.
  const [result, setResult] = useState(null);

  const form = useForm({
    resolver: zodResolver(passwordFormSchema),
    // Radix moves focus on open, which would blur the empty field and show an
    // error before typing. Validate on submit, then live.
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: { password: "" },
  });

  async function onSubmit(values) {
    try {
      const { data } = await updateUserPassword(
        database.id,
        user.id,
        values.password,
      );
      setResult(data?.user?.connection_string ?? null);
      form.reset({ password: "" });
      await refreshAndWait();
      toast.success(t("passwordChanged", { username: user.username }));
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  function handleOpenChange(next) {
    if (!next) {
      form.reset({ password: "" });
      setResult(null);
    }
    onOpenChange?.(next);
  }

  // Pre-filled with a generated password, fresh on each open so no two users
  // in a session share one.
  useEffect(() => {
    if (!open) return;
    form.setValue("password", randomPassword());
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  const isSubmitting = form.formState.isSubmitting;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={KeyRound}
        title={t("passwordTitle", { username: user?.username ?? "" })}
        description={t("passwordSubtitle")}
        footer={
          result ? (
            <Button type="button" onClick={() => handleOpenChange(false)}>
              {t("done")}
            </Button>
          ) : (
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
                {isSubmitting ? t("saving") : t("passwordSubmit")}
              </Button>
            </>
          )
        }
      >
        {result ? (
          <div className="space-y-1.5">
            <p className="text-xs font-medium text-muted-foreground">
              {t("newConnectionString")}
            </p>
            <div className="flex items-start gap-1.5 rounded-lg border bg-muted/40 px-3 py-2">
              <code className="min-w-0 flex-1 font-mono text-xs break-all">
                {result}
              </code>
              <CopyButton value={result} />
            </div>
          </div>
        ) : (
          <>
            <FormField
              control={form.control}
              name="password"
              render={({ field }) => (
                // Generate is placed by the label but follows the input in the
                // markup, so Tab reaches the field first. Same as the other
                // password fields in the panel.
                <FormItem className="relative">
                  <FormLabel required>{t("newPassword")}</FormLabel>
                  <FormControl>
                    <PasswordInput
                      autoComplete="new-password"
                      placeholder={t("passwordPlaceholder")}
                      {...field}
                    />
                  </FormControl>
                  <Button
                    type="button"
                    variant="link"
                    size="sm"
                    className="absolute top-0 right-0 h-auto p-0 text-xs"
                    onClick={() =>
                      form.setValue("password", randomPassword(), {
                        shouldDirty: true,
                        shouldValidate: true,
                      })
                    }
                  >
                    <Sparkles className="size-3" />
                    {t("generate")}
                  </Button>
                  <FormMessage field={t('newPassword')} />
                </FormItem>
              )}
            />
            <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed">
              {t("passwordWarning")}
            </p>
          </>
        )}
      </FormModal>
    </Form>
  );
}
