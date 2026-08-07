"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { KeyRound, Loader2, TriangleAlert } from "lucide-react";
import { replaceCredentialsSchema } from "@/lib/schemas/storage";
import { updateDestination } from "@/lib/api/storage";
import { probeDestination } from "@/lib/storage/probe";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { apiMessage } from "@/lib/api/error-message";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
import { FormModal } from "@/components/ui/form-modal";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/ui/form";

/**
 * Rotating the keys.
 *
 * Unlike the git equivalent, the API does NOT verify these before storing
 * them — it has no way to, since the check is a separate endpoint. So the old
 * working credentials are genuinely replaced by whatever is typed here, and
 * the dialog says so instead of implying a safety net it doesn't have. The
 * test runs straight after, so a bad rotation is caught in seconds rather than
 * at the next scheduled backup.
 */
export function ReplaceCredentialsDialog({ destination, open, onOpenChange }) {
  const t = useTranslations("storage.replace");
  const router = useRouter();
  const [failure, setFailure] = useState(null);

  const form = useForm({
    resolver: zodResolver(replaceCredentialsSchema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: { access_key: "", secret_key: "" },
  });

  async function onSubmit(values) {
    setFailure(null);
    try {
      await updateDestination(destination.id, {
        access_key: values.access_key.trim(),
        secret_key: values.secret_key.trim(),
      });
      onOpenChange?.(false);
      form.reset();
      router.refresh();

      const verdict = await probeDestination(destination.id, t("replacedButFailed"));
      if (verdict.ok) toast.success(t("replacedAndTested"));
      else toast.error(verdict.message, { duration: 10000 });
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const submitting = form.formState.isSubmitting;

  function handleOpenChange(next) {
    if (!next) {
      form.reset();
      setFailure(null);
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
        icon={KeyRound}
        title={t("title")}
        description={t("subtitle", { name: destination?.name ?? "" })}
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
        {/* No "we verify before replacing" promise here — that would be a lie
            about this endpoint. What it can honestly say is what breaks. */}
        <div className="flex items-start gap-2 rounded-lg border border-warning/40 bg-warning/10 p-3 text-sm">
          <TriangleAlert className="mt-0.5 size-4 shrink-0 text-warning" />
          <p>{t("warning")}</p>
        </div>

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
              <FormMessage />
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
              <FormMessage />
            </FormItem>
          )}
        />
        {failure ? <p className="text-xs text-destructive">{failure}</p> : null}
      </FormModal>
    </Form>
  );
}
