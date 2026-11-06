import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { KeyRound, Loader2, Sparkles } from "lucide-react";
import { rootPasswordSchema } from "@/lib/schemas/database";
import { setRootPassword } from "@/lib/api/databases";
import { generatePassword } from "@/lib/applications/generate-password";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Note } from "@/components/ui/note";
import { CopyablePasswordInput } from "@/components/system-users/copyable-password-input";
import { PasswordInput } from "@/components/ui/password-input";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/ui/form";

// Set, change or reset the MySQL/MariaDB root password (5fdb2007). The old one is asked
// for only when the panel cannot reach root either as the server's own root account or
// with the password it stored (`root_unreachable`, e.g. a server moved from v7).
const ASK_CURRENT = new Set(["root_unreachable", "current_password_wrong"]);

export function RootPasswordDialog({ engine, connection, open, onOpenChange }) {
  const t = useTranslations("databases.rootPassword");
  const tEngines = useTranslations("databases.engines");
  const { refreshThen } = useRefresh();
  const name = engine ? tEngines(engine) : "";
  const known = Boolean(connection?.has_root_password);

  const [askCurrent, setAskCurrent] = useState(false);
  const form = useForm({
    resolver: zodResolver(rootPasswordSchema),
    defaultValues: { password: "", password_confirmation: "", current_password: "" },
  });

  useEffect(() => {
    if (askCurrent) form.setFocus("current_password");
  }, [askCurrent, form]);

  function handleOpenChange(next) {
    // The form stays mounted: clear values and errors so a reopen starts clean.
    if (!next) {
      form.reset();
      setAskCurrent(false);
    }
    onOpenChange?.(next);
  }

  async function onSubmit({ current_password: current, ...values }) {
    if (askCurrent && !current) {
      form.setError("current_password", { message: "requiredField" });
      return;
    }
    try {
      await setRootPassword(engine, askCurrent && current ? { ...values, current_password: current } : values);
      await new Promise((resolve) => refreshThen(resolve));
      toast.success(t("saved", { engine: name }));
      handleOpenChange(false);
    } catch (error) {
      const data = error.response?.data;
      if (error.response?.status === 422 && ASK_CURRENT.has(data?.reason)) {
        // The first time it is a question, not a mistake: the field appears with its hint.
        if (askCurrent || data.reason === "current_password_wrong") {
          form.setError("current_password", { message: data.errors?.current_password?.[0] ?? data.message });
        }
        setAskCurrent(true);
        return;
      }
      // `errors.engine` (a change already running) has no field here.
      handleValidationError(error, form, { fallback: t("failed"), formError: true });
    }
  }

  const isSubmitting = form.formState.isSubmitting;
  const rootError = form.formState.errors.root?.server?.message;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        initialFocus="input[name=password]"
        icon={KeyRound}
        title={t("title", { engine: name })}
        description={known ? t("descriptionKnown") : t("descriptionUnknown")}
        footer={
          <>
            <Button type="button" variant="outline" disabled={isSubmitting} onClick={() => handleOpenChange(false)}>
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {isSubmitting ? t("saving") : known ? t("submitChange") : t("submitSet")}
            </Button>
          </>
        }
      >
        {/* What changes for root's other way in: MariaDB keeps it, MySQL cannot. */}
        <Note>{engine === "mysql" ? t("noteMysql") : t("noteMariadb")}</Note>

        {rootError ? (
          <p role="alert" className="rounded-lg border border-destructive/30 bg-destructive/5 px-3 py-2 text-sm text-destructive">
            {rootError}
          </p>
        ) : null}

        <div className="space-y-4">
          {askCurrent ? (
            <FormField
              control={form.control}
              name="current_password"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("current")}</FormLabel>
                  <FormControl>
                    <PasswordInput placeholder={t("currentPlaceholder")} autoComplete="current-password" {...field} />
                  </FormControl>
                  <p className="text-xs text-muted-foreground">{t("currentHint", { engine: name })}</p>
                  <FormMessage />
                </FormItem>
              )}
            />
          ) : null}
          <FormField
            control={form.control}
            name="password"
            render={({ field }) => (
              <FormItem className="relative">
                <FormLabel required>{t("new")}</FormLabel>
                <FormControl>
                  <CopyablePasswordInput copyLabel={t("copy")} placeholder={t("newPlaceholder")} autoComplete="new-password" {...field} />
                </FormControl>
                <Button
                  type="button"
                  variant="link"
                  size="sm"
                  className="absolute top-0 right-0 h-auto p-0 text-xs"
                  onClick={() => {
                    const value = generatePassword(24);
                    form.setValue("password", value, { shouldDirty: true, shouldValidate: true });
                    form.setValue("password_confirmation", value, { shouldDirty: true, shouldValidate: true });
                  }}
                >
                  <Sparkles className="size-3" />
                  {t("generate")}
                </Button>
                <FormMessage />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="password_confirmation"
            render={({ field }) => (
              <FormItem>
                <FormLabel required>{t("confirm")}</FormLabel>
                <FormControl>
                  <CopyablePasswordInput copyLabel={t("copy")} placeholder={t("confirmPlaceholder")} autoComplete="new-password" {...field} />
                </FormControl>
                <FormMessage />
              </FormItem>
            )}
          />
          <p className="text-xs text-muted-foreground">{t("rules")}</p>
        </div>
      </FormModal>
    </Form>
  );
}
