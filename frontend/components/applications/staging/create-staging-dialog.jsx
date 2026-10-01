import { useEffect, useRef, useState } from "react";
import { useRouter } from "next/navigation";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { FlaskConical, Loader2 } from "lucide-react";
import { createStagingFormSchema } from "@/lib/schemas/application-staging";
import { createApplicationStaging } from "@/lib/api/applications";
import { apiMessage } from "@/lib/api/error-message";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormControl,
  FormDescription,
  FormField,
  FormItem,
  FormLabel,
  FormMessage,
} from "@/components/ui/form";

// The request is synchronous (no job to poll), so the dialog stays open until it finishes.
// Callers MUST pass a `key` that changes on open so the field resets.
export function CreateStagingDialog({ appId, production, open, onOpenChange }) {
  const t = useTranslations("applications.staging.createDialog");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [awaitingPage, setAwaitingPage] = useState(false);
  // The success toast waits until the dialog unmounts.
  const announce = useRef(null);
  useEffect(() => () => announce.current?.(), []);
  useEffect(() => {
    if (!awaitingPage) return undefined;
    const timer = window.setTimeout(() => {
      announce.current?.();
      announce.current = null;
      setPending(false);
      onOpenChange(false);
    }, 20000);
    return () => window.clearTimeout(timer);
  }, [awaitingPage, onOpenChange]);

  // Suggested default; the user can type over it.
  const suggestion = production?.domain ? `staging.${production.domain}` : "";

  const form = useForm({
    resolver: zodResolver(createStagingFormSchema),
    mode: "onBlur",
    defaultValues: { domain: suggestion },
  });

  async function submit(values) {
    setPending(true);
    try {
      await createApplicationStaging(appId, values.domain);
      // Stays on "Creating…" until the refresh replaces the no-staging state
      // this dialog lives in.
      announce.current = () => toast.success(t("done", { domain: values.domain }));
      router.refresh();
      setAwaitingPage(true);
      return;
    } catch (error) {
      if (error.response?.data?.errors) {
        handleValidationError(error, form);
      } else {
        toast.error(apiMessage(error, t("failed")));
        // Usually a copy already exists (made elsewhere); refresh to show it.
        router.refresh();
      }
    }
    setPending(false);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={pending ? undefined : onOpenChange}
        asForm
        onSubmit={(event) => form.handleSubmit(submit)(event)}
        icon={FlaskConical}
        title={t("title")}
        description={t("description", { domain: production?.domain ?? "" })}
        className="sm:max-w-lg"
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              onClick={() => onOpenChange(false)}
              disabled={pending}
            >
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={pending}>
              {pending ? <Loader2 className="size-4 animate-spin" /> : null}
              {pending ? t("creating") : t("submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="domain"
          render={({ field }) => (
            <FormItem>
              <FormLabel hint={t("domainLabelHint")}>{t("domainLabel")}</FormLabel>
              <FormControl>
                <Input
                  {...field}
                  autoFocus
                  disabled={pending}
                  autoComplete="off"
                  spellCheck={false}
                  placeholder={t("domainPlaceholder")}
                  className="font-mono"
                />
              </FormControl>
              <FormDescription>{t("domainHint")}</FormDescription>
              <FormMessage />
            </FormItem>
          )}
        />

        {/* Warns up front: creation takes minutes with no progress reported. */}
        <p className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
          {t("slow")}
        </p>
      </FormModal>
    </Form>
  );
}
