import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Pencil, Sparkles } from "lucide-react";
import { databaseUserFormSchema } from "@/lib/schemas/database";
import { updateDatabaseUser } from "@/lib/api/databases";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { PasswordInput } from "@/components/ui/password-input";
import { randomPassword } from "@/lib/databases/random";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";
import { UserFields } from "@/components/databases/user-fields";

/**
 * Rename a user, or change where it may connect from.
 *
 * On SQL engines this is a `RENAME USER`, so the grants survive. On Mongo the
 * user is dropped and recreated — which is why renaming there needs a password,
 * and why the warning below only appears for Mongo.
 */
export function EditUserDialog({ database, user, open, onOpenChange }) {
  const t = useTranslations("databases.users");
  const tc = useTranslations("common");
  const router = useRouter();
  const isMongo = database?.driver === "mongo";

  const defaults = {
    username: user?.username ?? "",
    password: "",
    connection_preference: user?.connection_preference ?? "localhost",
    host: user?.host ?? "",
  };

  const form = useForm({
    resolver: zodResolver(databaseUserFormSchema),
    mode: "onBlur",
    defaultValues: defaults,
  });

  const values = useWatch({ control: form.control });

  async function onSubmit(submitted) {
    // PATCH: send only what changed, so an untouched field is never rewritten
    // on the engine for no reason.
    const payload = {};
    if (submitted.username !== defaults.username) {
      payload.username = submitted.username;
    }
    if (
      submitted.connection_preference !== defaults.connection_preference ||
      submitted.host !== defaults.host
    ) {
      payload.connection_preference = submitted.connection_preference;
      if (submitted.connection_preference === "remote") {
        payload.host = submitted.host;
      }
    }
    if (submitted.password) payload.password = submitted.password;

    if (Object.keys(payload).length === 0) {
      onOpenChange?.(false);
      return;
    }

    try {
      await updateDatabaseUser(database.id, user.id, payload);
      toast.success(t("updated"));
      onOpenChange?.(false);
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;
  const renaming = values.username !== defaults.username;
  // Mongo cannot rename without one, so the button says so before the request.
  const needsPassword = isMongo && renaming && !values.password;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={onOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={Pencil}
        title={t("editTitle", { username: user?.username ?? "" })}
        description={t("editSubtitle")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => onOpenChange?.(false)}
            >
              {t("cancel")}
            </Button>
            {/* Two different reasons, and the password one is the surprising
                half: a Mongo rename drops and recreates the user. */}
            <ReasonTooltip
              reason={
                isSubmitting
                  ? null
                  : needsPassword
                    ? tc("mongoRenameNeedsPassword")
                    : !form.formState.isDirty
                      ? tc("nothingToSave")
                      : null
              }
            >
              <Button
                type="submit"
                disabled={isSubmitting || !form.formState.isDirty || needsPassword}
              >
                {isSubmitting && <Loader2 className="size-4 animate-spin" />}
                {isSubmitting ? t("saving") : t("save")}
              </Button>
            </ReasonTooltip>
          </>
        }
      >
        <UserFields form={form} access={values.connection_preference} />

        {/* Only Mongo loses the credential on a rename, so only Mongo is
            warned — a warning shown to everyone is a warning nobody reads. */}
        {isMongo && renaming ? (
          <>
            <p className="rounded-lg border border-warning/40 bg-warning/10 px-3 py-2 text-xs leading-relaxed">
              {t("mongoRenameWarning")}
            </p>

            {/* Required here, and only here: MongoDB drops and recreates the
                user on a rename, so it needs a password to recreate it with.
                Warning about that without offering the field sent people
                straight into a 422. */}
            <FormField
              control={form.control}
              name="password"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("newPasswordRequired")}</FormLabel>
                  <div className="flex items-start gap-2">
                    <FormControl>
                      <PasswordInput
                        autoComplete="new-password"
                        placeholder={t("passwordPlaceholder")}
                        {...field}
                      />
                    </FormControl>
                    <Button
                      type="button"
                      variant="outline"
                      className="shrink-0"
                      onClick={() =>
                        form.setValue("password", randomPassword(), {
                          shouldDirty: true,
                        })
                      }
                    >
                      <Sparkles className="size-4" />
                      {t("generate")}
                    </Button>
                  </div>
                  <FormMessage />
                </FormItem>
              )}
            />
          </>
        ) : null}

        <p className="text-xs text-muted-foreground">{t("editHint")}</p>
      </FormModal>
    </Form>
  );
}
