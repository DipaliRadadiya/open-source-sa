"use client";

import { useState } from "react";
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
      onOpenChange(false);
      toast.success(t("done", { domain: values.domain }));
      router.refresh();
    } catch (error) {
      if (error.response?.data?.errors) {
        handleValidationError(error, form);
      } else {
        toast.error(apiMessage(error, t("failed")));
      }
    } finally {
      setPending(false);
    }
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={pending ? undefined : onOpenChange}
        asForm
        onSubmit={form.handleSubmit(submit)}
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
              <FormLabel>{t("domainLabel")}</FormLabel>
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
