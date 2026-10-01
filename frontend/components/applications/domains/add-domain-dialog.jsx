import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Globe, Info } from "lucide-react";
import { addDomainFormSchema, REDIRECT_STATUSES } from "@/lib/schemas/domain";
import { isValidApplicationDomain, suggestApplicationDomain } from "@/lib/schemas/application";
import { addDomain } from "@/lib/api/domains";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
import { CopyButton } from "@/components/ui/copy-button";
import { Input } from "@/components/ui/input";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormDescription,
  FormMessage,
} from "@/components/ui/form";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

/**
 * @param certificate the site's certificate, or null. Only an active one
 *   matters: a pending or failed one is not serving anything.
 */
export function AddDomainDialog({ appId, open, onOpenChange, serverIp = null, certificate = null }) {
  const t = useTranslations("applications.domains");
  const tForm = useTranslations("applications.form");
  const { pending: refreshing, refreshThen } = useRefresh();

  const active = certificate?.status === "active" ? certificate : null;
  // An uploaded certificate cannot be re-issued here, so the advice differs.
  const uploaded = active?.type === "custom";

  const form = useForm({
    resolver: zodResolver(addDomainFormSchema),
    defaultValues: { domain: "", type: "alias", redirect_to: "", redirect_status: 301 },
  });

  const type = useWatch({ control: form.control, name: "type" });
  const typedDomain = useWatch({ control: form.control, name: "domain" });
  // Capitals alone are not worth a suggestion: the form lowercases them.
  const suggestedDomain = isValidApplicationDomain(typedDomain) ? null : suggestApplicationDomain(typedDomain);

  async function onSubmit(values) {
    const body =
      values.type === "redirect"
        ? values
        : { domain: values.domain, type: values.type };
    try {
      await addDomain(appId, body);
      // On a secured site, the toast repeats that the new name is not covered yet.
      refreshThen(() => {
        toast.success(t("toast.added"), {
          description: active
            ? t(uploaded ? "toast.addedNeedsUpload" : "toast.addedNeedsReissue", {
                domain: values.domain,
              })
            : undefined,
        });
        onOpenChange?.(false);
        form.reset();
      });
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting || refreshing;

  function handleOpenChange(next) {
    if (!next) form.reset();
    onOpenChange?.(next);
  }

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={handleOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={Globe}
        title={t("add.title")}
        description={t("add.subtitle")}
        footer={
          <>
            <Button type="button" variant="outline" disabled={isSubmitting} onClick={() => handleOpenChange(false)}>
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("add.submitting") : t("add.submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="domain"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("add.domain")}</FormLabel>
              <FormControl>
                <Input placeholder="example.com" autoComplete="off" spellCheck={false} {...field} />
              </FormControl>
              {/* Offer the hostname inside a pasted URL, as the create form does. */}
              {suggestedDomain ? (
                <FormDescription className="flex flex-wrap items-center gap-x-2 gap-y-1">
                  <span>{tForm("domainSuggestion")}</span>
                  <button
                    type="button"
                    onClick={() =>
                      form.setValue("domain", suggestedDomain, { shouldDirty: true, shouldValidate: true })
                    }
                    className="font-medium text-primary hover:underline"
                  >
                    {tForm("useDomain", { domain: suggestedDomain })}
                  </button>
                </FormDescription>
              ) : null}
              <FormMessage />
            </FormItem>
          )}
        />

        <FormField
          control={form.control}
          name="type"
          render={({ field }) => (
            <FormItem>
              <FormLabel hint={t("add.typeHint")}>{t("add.type")}</FormLabel>
              <Select value={field.value} onValueChange={field.onChange}>
                <FormControl>
                  {/* w-full: SelectTrigger defaults to w-fit. */}
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  <SelectItem value="alias">{t("type.alias")}</SelectItem>
                  <SelectItem value="redirect">{t("type.redirect")}</SelectItem>
                </SelectContent>
              </Select>
              {/* Users often pick "alias" when they mean "redirect"; spell out
                  the difference. */}
              <p className="text-xs text-muted-foreground">
                {type === "redirect" ? t("add.redirectHint") : t("add.aliasHint")}
              </p>
              <FormMessage />
            </FormItem>
          )}
        />

        {type === "redirect" ? (
          <>
            <FormField
              control={form.control}
              name="redirect_to"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required hint={t("add.redirectToHint")}>{t("add.redirectTo")}</FormLabel>
                  <FormControl>
                    <Input placeholder="https://example.com" autoComplete="off" spellCheck={false} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="redirect_status"
              render={({ field }) => (
                <FormItem>
                  <FormLabel hint={t("add.redirectStatusHint")}>{t("add.redirectStatus")}</FormLabel>
                  <Select value={String(field.value)} onValueChange={(v) => field.onChange(Number(v))}>
                    <FormControl>
                      <SelectTrigger className="w-full">
                        <SelectValue />
                      </SelectTrigger>
                    </FormControl>
                    {/* Options are named, with the status code kept alongside. */}
                    <SelectContent>
                      {REDIRECT_STATUSES.map((code) => (
                        <SelectItem key={code} value={String(code)}>
                          {t(`add.redirectStatusOption.${code}`)}
                        </SelectItem>
                      ))}
                    </SelectContent>
                  </Select>
                  <FormMessage />
                </FormItem>
              )}
            />
          </>
        ) : null}

        {/* A name does nothing until its DNS points here. On a secured site the
            neutral text is used, since `dnsNote` promises HTTPS "can be issued"
            and the notice below covers the certificate. */}
        <div className="flex items-center gap-2 rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">
          <Info className="size-3.5 shrink-0" />
          {serverIp ? (
            <p className="flex flex-wrap items-center gap-1.5">
              <span>{t("add.dnsNoteIp")}</span>
              <code className="rounded bg-background px-1.5 py-0.5 font-mono text-foreground">
                {serverIp}
              </code>
              <CopyButton value={serverIp} className="size-6" />
            </p>
          ) : (
            <p>{t(active ? "add.dnsNoteSecured" : "add.dnsNote")}</p>
          )}
        </div>

        {/* On an HTTPS site the new name joins the TLS server block, so it
            answers on 443 with a certificate that does not cover it and the
            browser refuses the page. */}
        {active ? (
          <Caution size="md">
            <p>
              {t(uploaded ? "add.certUploadedNotice" : "add.certNotice", {
                current: active.type_title ?? "",
              })}
            </p>
            {/* With force-HTTPS on, port 80 also redirects to that certificate
                error, so the name is unreachable. */}
            {active.force_https ? <p>{t("add.certNoticeForceHttps")}</p> : null}
          </Caution>
        ) : null}
      </FormModal>
    </Form>
  );
}
