import { useEffect } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Pencil, Lock } from "lucide-react";
import { editDomainFormSchema } from "@/lib/schemas/domain";
import { updateDomain } from "@/lib/api/domains";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
import { FormModal } from "@/components/ui/form-modal";
import { WrappedDomain } from "@/components/ui/domain-text";
import { Form, FormLabel } from "@/components/ui/form";
import { DomainTypeFields } from "@/components/applications/domains/domain-type-fields";

export function EditDomainDialog({ appId, domain, open, onOpenChange }) {
  const t = useTranslations("applications.domains");
  const { pending: refreshing, refreshThen } = useRefresh();

  const form = useForm({
    resolver: zodResolver(editDomainFormSchema),
    defaultValues: { type: "alias", redirect_to: "", redirect_status: 301 },
  });

  useEffect(() => {
    if (!open || !domain) return;
    form.reset({
      type: domain.type === "redirect" ? "redirect" : "alias",
      redirect_to: domain.redirect_to ?? "",
      redirect_status: domain.redirect_status ?? 301,
    });
  }, [open, domain, form]);

  const type = useWatch({ control: form.control, name: "type" });
  const dropsTarget = domain?.type === "redirect" && type === "alias" && Boolean(domain?.redirect_to);

  async function onSubmit(values) {
    const body =
      values.type === "redirect"
        ? values
        : { type: values.type };
    try {
      await updateDomain(appId, domain.domain, body);
      refreshThen(() => {
        toast.success(t("toast.updated", { domain: domain.domain }));
        onOpenChange?.(false);
      });
    } catch (error) {
      handleValidationError(error, form, { fallback: t("toast.updateFailed") });
    }
  }

  const isSubmitting = form.formState.isSubmitting || refreshing;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={onOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={Pencil}
        title={t("edit.title")}
        description={t("edit.subtitle")}
        footer={
          <>
            <Button type="button" variant="outline" disabled={isSubmitting} onClick={() => onOpenChange?.(false)}>
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("edit.submitting") : t("edit.submit")}
            </Button>
          </>
        }
      >
        <div className="space-y-2">
          <FormLabel className="text-muted-foreground">{t("edit.domain")}</FormLabel>
          <div className="flex items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2">
            <Lock className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
            <WrappedDomain domain={domain?.domain} className="min-w-0 font-mono text-sm" />
          </div>
          <p className="text-xs text-muted-foreground">{t("edit.domainLocked")}</p>
        </div>

        <DomainTypeFields form={form} />

        {dropsTarget ? (
          <Caution size="md">{t("edit.clearsTarget", { target: domain.redirect_to })}</Caution>
        ) : null}
      </FormModal>
    </Form>
  );
}
