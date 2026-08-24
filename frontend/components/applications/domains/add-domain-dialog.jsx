"use client";

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

export function AddDomainDialog({ appId, open, onOpenChange, serverIp = null }) {
  const t = useTranslations("applications.domains");
  const router = useRouter();

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
      toast.success(t("toast.added"));
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
              <FormLabel>{t("add.type")}</FormLabel>
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
                  <FormLabel required>{t("add.redirectTo")}</FormLabel>
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
                  <FormLabel>{t("add.redirectStatus")}</FormLabel>
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
            here. Show the exact A-record target when we know it. */}
        <div className="flex items-start gap-2 rounded-lg bg-muted/50 p-3 text-xs text-muted-foreground">
          <Info className="mt-0.5 size-3.5 shrink-0" />
          {serverIp ? (
            <p className="flex flex-wrap items-center gap-1.5">
              <span>{t("add.dnsNoteIp")}</span>
              <code className="rounded bg-background px-1.5 py-0.5 font-mono text-foreground">
                {serverIp}
              </code>
              <CopyButton value={serverIp} className="size-6" />
            </p>
          ) : (
            <p>{t("add.dnsNote")}</p>
          )}
        </div>
      </FormModal>
    </Form>
  );
}
