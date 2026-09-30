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

/**
 * Make the copy.
 *
 * One field, because the backend takes one: everything else about the staging
 * site is derived from production. The wait is the notable part — creating
 * provisions a site and rsyncs the whole document root synchronously, with no
 * job to poll — so the dialog holds itself open and says so rather than
 * closing on a request that has not finished.
 *
 * Callers MUST pass a `key` that changes when this opens, so a domain typed
 * once is not still in the field the next time.
 */
export function CreateStagingDialog({ appId, production, open, onOpenChange }) {
  const t = useTranslations("applications.staging.createDialog");
  const router = useRouter();
  const [pending, setPending] = useState(false);
  const [awaitingPage, setAwaitingPage] = useState(false);
  // The success toast waits for the dialog to go: shown on the API's answer,
  // it sat beside a dialog still saying "Creating…" for seconds.
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

  // Offered, not imposed: `staging.` in front of the production domain is what
  // almost everyone types, and an empty box makes them invent it. Anyone with
  // another convention types over it, and the DNS caveat below still applies
  // either way.
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
      // Open on "Creating…" until the page shows the copy — this dialog lives
      // in the no-staging state, so it goes when that state does. Closing
      // first uncovered "Create staging" for the length of the refresh.
      announce.current = () => toast.success(t("done", { domain: values.domain }));
      router.refresh();
      setAwaitingPage(true);
      return;
    } catch (error) {
      if (error.response?.data?.errors) {
        handleValidationError(error, form);
      } else {
        toast.error(apiMessage(error, t("failed")));
        // Most often another tab made a copy first; the page behind still
        // offered to create one. Re-read so it shows the copy that exists.
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

        {/* The wait is minutes, not seconds, and nothing reports progress.
            Better said before the click than discovered after it. */}
        <p className="rounded-lg border bg-muted/40 p-3 text-sm text-muted-foreground">
          {t("slow")}
        </p>
      </FormModal>
    </Form>
  );
}
