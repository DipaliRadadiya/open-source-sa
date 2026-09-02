import { useState } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, UserRoundPlus } from "lucide-react";
import { databaseUserFormSchema } from "@/lib/schemas/database";
import { createDatabaseUser } from "@/lib/api/databases";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { FormModal } from "@/components/ui/form-modal";
import { Form } from "@/components/ui/form";
import { UserFields } from "@/components/databases/user-fields";
import { CreatedCredentials } from "@/components/databases/created-credentials";

export function AddUserDialog({ database, open, onOpenChange }) {
  const t = useTranslations("databases.users");
  const router = useRouter();
  // Set on success: the new credential replaces the form, because a password
  // you are never shown is a password nobody can use.
  const [created, setCreated] = useState(null);

  const defaults = {
    username: "",
    password: "",
    connection_preference: "localhost",
    host: "",
  };

  const form = useForm({
    resolver: zodResolver(databaseUserFormSchema),
    // See create-database-dialog: blur-time errors on a half-filled new form
    // read as being told off for moving to the next field.
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: defaults,
  });

  const values = useWatch({ control: form.control });

  async function onSubmit(submitted) {
    const payload = {
      username: submitted.username,
      connection_preference: submitted.connection_preference,
    };
    // Omitted means the API generates one, which beats anything typed in a
    // hurry.
    if (submitted.password) payload.password = submitted.password;
    if (submitted.connection_preference === "remote") {
      payload.host = submitted.host;
    }

    try {
      const { data } = await createDatabaseUser(database.id, payload);
      toast.success(t("added", { username: submitted.username }));
      setCreated({ ...database, users: [data?.user].filter(Boolean) });
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  function handleOpenChange(next) {
    if (!next) {
      form.reset(defaults);
      setCreated(null);
    }
    onOpenChange?.(next);
  }

  if (created) {
    return (
      <CreatedCredentials
        database={created}
        open={open}
        onOpenChange={handleOpenChange}
      />
    );
  }

  const isSubmitting = form.formState.isSubmitting;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={UserRoundPlus}
        title={t("addTitle", { name: database?.name ?? "" })}
        description={t("addSubtitle")}
        footer={
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
              {isSubmitting ? t("adding") : t("addSubmit")}
            </Button>
          </>
        }
      >
        <UserFields form={form} access={values.connection_preference} />
        <p className="text-xs text-muted-foreground">{t("passwordGenerated")}</p>
      </FormModal>
    </Form>
  );
}
