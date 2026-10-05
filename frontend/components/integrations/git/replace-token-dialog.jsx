import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { ExternalLink, KeyRound, Loader2, TriangleAlert } from "lucide-react";
import { replaceTokenSchema } from "@/lib/schemas/git";
import { updateAccount } from "@/lib/api/git";
import { createTokenUrl } from "@/lib/git/provider-links";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { apiMessage } from "@/lib/api/error-message";
import { useBranding } from "@/components/branding-provider";
import { Button } from "@/components/ui/button";
import { PasswordInput } from "@/components/ui/password-input";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormControl,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

// The API verifies the new token first, so a rejected one leaves the working credential in place.
export function ReplaceTokenDialog({ account, open, onOpenChange }) {
  const t = useTranslations("git.replace");
  // Reuses the connect form's per-provider token strings.
  const tConnect = useTranslations("git.connect");
  const { name: brand } = useBranding();
  const { refreshAndWait } = useRefresh();
  const [failure, setFailure] = useState(null);

  const form = useForm({
    resolver: zodResolver(replaceTokenSchema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: { token: "" },
  });

  async function onSubmit(values) {
    setFailure(null);
    try {
      await updateAccount(account.id, { token: values.token });
      await refreshAndWait();
      toast.success(t("replaced"));
      onOpenChange?.(false);
    } catch (error) {
      if (error.response?.data?.errors) {
        handleValidationError(error, form);
        return;
      }
      const rejected = t("rejected", { provider: account.provider_title });
      // Same as connecting: a 422 is a refused token named by lowercase id.
      setFailure(
        error.response?.status === 422 ? rejected : apiMessage(error, t("failed")),
      );
    }
  }

  const submitting = form.formState.isSubmitting;
  const tokenUrl = createTokenUrl(account?.provider, account?.host, brand);

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={onOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={KeyRound}
        title={t("title", { label: account?.label ?? "" })}
        description={t("subtitle", { provider: account?.provider_title ?? "" })}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={submitting}
              onClick={() => onOpenChange?.(false)}
            >
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={submitting}>
              {submitting ? <Loader2 className="size-4 animate-spin" /> : null}
              {submitting
                ? t("verifying", { provider: account?.provider_title ?? "" })
                : t("submit")}
            </Button>
          </>
        }
      >
        {failure ? (
          <div className="flex gap-2 rounded-lg border border-destructive/40 bg-destructive/10 px-3 py-2">
            <TriangleAlert className="mt-0.5 size-4 shrink-0 text-destructive" />
            <p className="text-xs leading-relaxed">{failure}</p>
          </div>
        ) : null}

        <FormField
          control={form.control}
          name="token"
          render={({ field }) => (
            <FormItem>
              <FormLabel required hint={t("tokenLabelHint")}>{t("tokenLabel")}</FormLabel>
              <FormControl>
                <PasswordInput
                  autoComplete="off"
                  /* Names the token the way each provider does. */
                  placeholder={
                    tConnect.has(`placeholders.token_${account?.provider}`)
                      ? tConnect(`placeholders.token_${account?.provider}`)
                      : t("tokenPlaceholder")
                  }
                  spellCheck={false}
                  {...field}
                  onChange={(event) => field.onChange(event.target.value.trim())}
                />
              </FormControl>
              {tokenUrl ? (
                <a
                  href={tokenUrl}
                  target="_blank"
                  rel="noreferrer noopener"
                  className="inline-flex items-center gap-1 text-xs font-medium text-primary underline-offset-4 hover:underline"
                >
                  {t("createToken", { provider: account?.provider_title ?? "" })}
                  <ExternalLink className="size-3" />
                </a>
              ) : null}
              <FormMessage field={t("tokenLabel")} />
            </FormItem>
          )}
        />

        <p className="rounded-lg border bg-muted/40 px-3 py-2 text-xs leading-relaxed text-muted-foreground">
          {t("safety")}
        </p>
      </FormModal>
    </Form>
  );
}
