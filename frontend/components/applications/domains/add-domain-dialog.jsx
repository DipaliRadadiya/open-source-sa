import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Globe, Info } from "lucide-react";
import { addDomainFormSchema, REDIRECT_STATUSES } from "@/lib/schemas/domain";
import { addDomain } from "@/lib/api/domains";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
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
 * @param certificate what secures the site today, or null. Only an *active*
 *   one matters here: a pending or failed certificate is not serving anything,
 *   so warning about the coverage of a certificate that does not exist yet
 *   would be noise on top of a problem the SSL card is already reporting.
 */
export function AddDomainDialog({ appId, open, onOpenChange, serverIp = null, certificate = null }) {
  const t = useTranslations("applications.domains");
  const router = useRouter();

  const active = certificate?.status === "active" ? certificate : null;
  // An uploaded certificate cannot be re-issued from this panel, so the advice
  // inverts: there is no button to press, and telling the user to "reissue"
  // sends them looking for one that is not there.
  const uploaded = active?.type === "custom";

  const form = useForm({
    resolver: zodResolver(addDomainFormSchema),
    defaultValues: { domain: "", type: "alias", redirect_to: "", redirect_status: 301 },
  });

  const type = useWatch({ control: form.control, name: "type" });

  async function onSubmit(values) {
    // Only send redirect fields when they matter.
    const body =
      values.type === "redirect"
        ? values
        : { domain: values.domain, type: values.type };
    try {
      await addDomain(appId, body);
      // Said again on the way out. The dialog explained this before the click,
      // but the one thing left undone after adding a name to a secured site is
      // covering it — and a bare "Domain added." reads as finished.
      toast.success(t("toast.added"), {
        description: active
          ? t(uploaded ? "toast.addedNeedsUpload" : "toast.addedNeedsReissue", {
              domain: values.domain,
            })
          : undefined,
      });
      onOpenChange?.(false);
      form.reset();
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

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
                  {/* w-full for the same reason as the certificate dialog:
                      SelectTrigger defaults to w-fit, and this one sits
                      directly under a full-width domain input. */}
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  <SelectItem value="alias">{t("type.alias")}</SelectItem>
                  <SelectItem value="redirect">{t("type.redirect")}</SelectItem>
                </SelectContent>
              </Select>
              {/* The alias/redirect choice is not cosmetic — most users pick
                  "alias" when they mean "redirect". Spell out the difference. */}
              <p className="text-xs text-muted-foreground">
                {type === "redirect" ? t("add.redirectHint") : t("add.aliasHint")}
              </p>
              <FormMessage />
            </FormItem>
          )}
        />

        {/* One field per row, like every other field here. These two used to
            share a row, which made "Redirect to" the only input in the form
            narrower than the rest — and it lined up with nothing above it. The
            modal body is already `space-y-4`, the same gap the grid used. */}
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
                    {/* Named, not numbered. The list used to read
                        "301 · 302 · 307 · 308", which tells you which one to
                        pick only if you already knew. The number stays because
                        it is what every other tool calls it. */}
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

        {/* Set expectations up front: a name does nothing until its DNS points
            here. Show the exact A-record target when we know it.

            `dnsNote` promises HTTPS "can be issued", which is true of a site
            with no certificate and misleading for one that already has a
            certificate this name will not be on — so the secured case says the
            neutral half and leaves the certificate to the notice below. */}
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

        {/* The consequence of adding a name to a site that is already serving
            HTTPS, said before the click rather than discovered by a visitor.

            The new name goes into the TLS server block's `server_name` along
            with every other — the vhost does not filter by what the
            certificate covers — so it answers on 443 presenting a certificate
            issued for somebody else's name, and the browser refuses the page
            outright. That is a harder failure than plain HTTP would be. */}
        {active ? (
          <Caution size="md">
            <p>
              {t(uploaded ? "add.certUploadedNotice" : "add.certNotice", {
                current: active.type_title ?? "",
              })}
            </p>
            {/* The sharp edge, and only when it is actually sharp. With the
                redirect off, the new name still answers on plain HTTP, so a
                visitor sees the site and no warning. With it on, port 80
                sends them to the certificate error and there is no way
                through. */}
            {active.force_https ? <p>{t("add.certNoticeForceHttps")}</p> : null}
          </Caution>
        ) : null}
      </FormModal>
    </Form>
  );
}
