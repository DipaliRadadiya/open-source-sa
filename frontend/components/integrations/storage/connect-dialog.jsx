import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { HardDrive, Loader2 } from "lucide-react";
import { createStorageDestinationSchema } from "@/lib/schemas/storage";
import { createRequirements } from "@/lib/storage/requirements";
import { createDestination } from "@/lib/api/storage";
import { probeDestination } from "@/lib/storage/probe";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
import { FormModal } from "@/components/ui/form-modal";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/ui/form";
import { DestinationFormFields } from "@/components/integrations/storage/destination-form-fields";

/**
 * Adding a destination.
 *
 * The API cannot check the credentials at creation — its test endpoint takes a
 * saved destination id — so this saves first and then immediately tests, and
 * reports the result of that test rather than a bare "Added". Without it, a
 * mistyped secret key looks like success here and only surfaces as a failed
 * backup at 3am, which is the worst possible moment to learn it.
 */
export function ConnectDestinationDialog({ open, onOpenChange }) {
  const t = useTranslations("storage.connect");
  const router = useRouter();

  const form = useForm({
    resolver: zodResolver(createStorageDestinationSchema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: {
      provider: "aws",
      name: "",
      endpoint: "",
      region: "",
      bucket: "",
      prefix: "",
      access_key: "",
      secret_key: "",
    },
  });

  async function onSubmit(values) {
    try {
      const { data } = await createDestination({
        name: values.name.trim(),
        bucket: values.bucket.trim(),
        endpoint: values.endpoint?.trim() || undefined,
        region: values.region?.trim() || undefined,
        prefix: values.prefix?.trim() || undefined,
        access_key: values.access_key.trim(),
        secret_key: values.secret_key.trim(),
      });

      const created = data?.storage_destination;
      toast.success(t("added"));
      onOpenChange?.(false);
      form.reset();
      router.refresh();

      // Saved is not the same as working. The check runs after the dialog
      // closes so it never blocks the save, and its verdict is what the user
      // is actually told.
      if (created?.id) {
        const verdict = await probeDestination(created.id, t("testFailed"));
        if (verdict.ok) toast.success(t("testPassed"));
        else toast.error(verdict.message, { duration: 10000 });
      }
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const submitting = form.formState.isSubmitting;
  // The provider is a form field rather than local state so the schema can see
  // it: which of endpoint and region is required depends on it.
  const provider = useWatch({ control: form.control, name: "provider" });

  function handleProviderChange(next) {
    form.setValue("provider", next);
    // Requiredness moves with the provider, so an error raised under the old
    // one has to be re-judged — otherwise "Endpoint is required" stays on
    // screen after switching to AWS, where it is not.
    if (form.formState.isSubmitted) form.trigger(["endpoint", "region"]);
  }

  function handleOpenChange(next) {
    if (!next) {
      form.reset();
    }
    onOpenChange?.(next);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={HardDrive}
        title={t("title")}
        description={t("subtitle")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={submitting}
              onClick={() => handleOpenChange(false)}
            >
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {submitting ? t("saving") : t("submit")}
            </Button>
          </>
        }
      >
        <DestinationFormFields
          form={form}
          provider={provider}
          onProviderChange={handleProviderChange}
          disabled={submitting}
          required={createRequirements(provider)}
        />

        {/* Said BEFORE the key is created, not after the test fails. The probe
            writes an object, reads it back and deletes it, and backups prune
            old archives when they pass the retention limit — so a read-only
            key cannot work, and that is the single most common reason one of
            these never starts working. */}
        <div className="rounded-lg border bg-muted/40 p-3 text-xs leading-relaxed text-muted-foreground">
          {t("permissionsNote")}
        </div>

        <div className="space-y-4">
          <FormField
            control={form.control}
            name="access_key"
            render={({ field }) => (
              <FormItem>
                <FormLabel required>{t("accessKey")}</FormLabel>
                <FormControl>
                  <PasswordInput
                    autoComplete="off"
                    placeholder={t("accessKeyPlaceholder")}
                    disabled={submitting}
                    {...field}
                  />
                </FormControl>
                <FormMessage field={t("accessKey")} />
              </FormItem>
            )}
          />
          <FormField
            control={form.control}
            name="secret_key"
            render={({ field }) => (
              <FormItem>
                <FormLabel required>{t("secretKey")}</FormLabel>
                <FormControl>
                  <PasswordInput
                    autoComplete="new-password"
                    placeholder={t("secretKeyPlaceholder")}
                    disabled={submitting}
                    {...field}
                  />
                </FormControl>
                <FormMessage field={t("secretKey")} />
              </FormItem>
            )}
          />
        </div>

        {/* Said before they are typed: these are stored encrypted and the API
            never sends them back, so the panel genuinely cannot show them
            again — replacing is the only way to change them later. */}
        <p className="text-xs text-muted-foreground">{t("credentialsNote")}</p>
      </FormModal>
    </Form>
  );
}
