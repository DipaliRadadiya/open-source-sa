import { useMemo } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { KeyRound, Loader2, TriangleAlert } from "lucide-react";
import { replaceCredentialsSchema } from "@/lib/schemas/storage";
import { TEXTAREA, secretFieldsFor } from "@/lib/storage/providers";
import { updateDestination } from "@/lib/api/storage";
import { probeDestination } from "@/lib/storage/probe";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
import { Textarea } from "@/components/ui/textarea";
import { FormModal } from "@/components/ui/form-modal";
import { Form, FormControl, FormField, FormItem, FormLabel, FormMessage } from "@/components/ui/form";

/**
 * Rotating the credentials.
 *
 * Which credentials those are depends on the provider — rotating an SFTP
 * private key is not rotating an access key and secret key pair — so the
 * fields come from the same declaration the create form uses rather than being
 * the hardcoded S3 pair this dialog used to assume.
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
  const tf = useTranslations("storage.form");
  const router = useRouter();

  const provider = destination?.provider ?? "s3";
  const fields = useMemo(() => secretFieldsFor(provider), [provider]);
  const schema = useMemo(() => replaceCredentialsSchema(destination), [destination]);

  const defaults = useMemo(
    () => Object.fromEntries(fields.map((f) => [f.name, ""])),
    [fields],
  );

  const form = useForm({
    resolver: zodResolver(schema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: defaults,
  });

  async function onSubmit(values) {
    try {
      // Only what was actually typed. An empty string here is not "clear it" —
      // it is "I did not rotate this one", which matters for SFTP where the
      // user rotates either the password or the key, never both.
      const config = Object.fromEntries(
        Object.entries(values)
          .map(([key, value]) => [key, String(value ?? "").trim()])
          .filter(([, value]) => value !== ""),
      );

      await updateDestination(destination.id, { config });
      onOpenChange?.(false);
      form.reset(defaults);
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
    if (!next) form.reset(defaults);
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

        {fields.map((definition) => (
          <FormField
            key={definition.name}
            control={form.control}
            name={definition.name}
            render={({ field }) => (
              <FormItem>
                {/* The same `help.<field>` text the add/edit form prints under
                    each input. This dialog has no such line — it is a short,
                    tense screen about replacing a live credential — so it goes
                    behind a "?" instead of being absent, which is what it was.
                    Not every field has one; `t.has` is what keeps the icon off
                    the ones that explain themselves. */}
                <FormLabel
                  hint={
                    tf.has(`help.${definition.name}`)
                      ? tf(`help.${definition.name}`)
                      : undefined
                  }
                >
                  {tf(`fields.${definition.name}`)}
                </FormLabel>
                <FormControl>
                  {definition.kind === TEXTAREA ? (
                    <Textarea
                      rows={4}
                      className="font-mono text-xs"
                      autoComplete="off"
                      spellCheck={false}
                      disabled={submitting}
                      {...field}
                    />
                  ) : (
                    <PasswordInput
                      autoComplete="new-password"
                      spellCheck={false}
                      disabled={submitting}
                      className={definition.mono ? "font-mono" : undefined}
                      {...field}
                    />
                  )}
                </FormControl>
                <FormMessage field={tf(`fields.${definition.name}`)} />
              </FormItem>
            )}
          />
        ))}

        {/* SFTP takes a password OR a key, so "fill in the one you are
            changing" is the actual instruction — not "fill in everything". */}
        <p className="text-xs text-muted-foreground">{t("onlyWhatYouChange")}</p>
      </FormModal>
    </Form>
  );
}
