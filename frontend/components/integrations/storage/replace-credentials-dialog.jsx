import { useMemo } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
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

// Unlike git, the API does NOT verify these before storing; the test runs straight after.
export function ReplaceCredentialsDialog({ destination, open, onOpenChange }) {
  const t = useTranslations("storage.replace");
  const tf = useTranslations("storage.form");
  const { refreshAndWait } = useRefresh();

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
      // Only what was typed: empty means "not rotating this one" (SFTP rotates password or key).
      const config = Object.fromEntries(
        Object.entries(values)
          .map(([key, value]) => [key, String(value ?? "").trim()])
          .filter(([, value]) => value !== ""),
      );

      await updateDestination(destination.id, { config });
      await refreshAndWait();
      onOpenChange?.(false);
      form.reset(defaults);

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
        {/* States what breaks; this endpoint does not verify first. */}
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
                {/* The form's `help.<field>` text behind a "?"; `t.has` skips fields without help. */}
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

        {/* SFTP takes a password OR a key, so only the changed one is filled. */}
        <p className="text-xs text-muted-foreground">{t("onlyWhatYouChange")}</p>
      </FormModal>
    </Form>
  );
}
