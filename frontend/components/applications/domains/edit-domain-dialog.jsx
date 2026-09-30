import { useEffect } from "react";
import { useForm, useWatch } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, Pencil, Lock } from "lucide-react";
import { editDomainFormSchema, REDIRECT_STATUSES } from "@/lib/schemas/domain";
import { updateDomain } from "@/lib/api/domains";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { useRefresh } from "@/hooks/use-refresh";
import { Button } from "@/components/ui/button";
import { Caution } from "@/components/ui/caution";
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
 * Change what an attached name DOES.
 *
 * Before `PUT …/domains/{domain}` existed, fixing a mistyped redirect target
 * meant deleting the name and adding it back — and the gap between the two is
 * not harmless. The vhost is re-rendered without the name in between, so the
 * redirect stops answering; and an add that fails or is forgotten leaves the
 * name off a site whose certificate still covers it, which is the state that
 * silently kills renewal for every other name on that certificate.
 *
 * @param domain the row being edited, or null when the dialog is closed.
 */
export function EditDomainDialog({ appId, domain, open, onOpenChange }) {
  const t = useTranslations("applications.domains");
  const { pending: refreshing, refreshThen } = useRefresh();

  const form = useForm({
    resolver: zodResolver(editDomainFormSchema),
    defaultValues: { type: "alias", redirect_to: "", redirect_status: 301 },
  });

  /*
   * Seeded from the row each time it opens, not once at mount.
   *
   * The dialog lives beside the list rather than inside each row, so one
   * instance serves every name. Without this, opening it on a second row
   * showed the first row's values — and worse, submitting would have written
   * them to a name they never belonged to.
   */
  useEffect(() => {
    if (!open || !domain) return;
    form.reset({
      type: domain.type === "redirect" ? "redirect" : "alias",
      redirect_to: domain.redirect_to ?? "",
      redirect_status: domain.redirect_status ?? 301,
    });
  }, [open, domain, form]);

  const type = useWatch({ control: form.control, name: "type" });
  // Switching away from a redirect throws its target away — the server clears
  // the column, because an alias serves the site itself and a target left on
  // one is a value nothing reads and every screen shows. Said before the
  // click, not discovered after it.
  const dropsTarget = domain?.type === "redirect" && type === "alias" && Boolean(domain?.redirect_to);

  async function onSubmit(values) {
    // Only send the redirect fields when they mean anything — the server
    // clears the target itself on a switch to alias.
    const body =
      values.type === "redirect"
        ? values
        : { type: values.type };
    try {
      await updateDomain(appId, domain.domain, body);
      // Closed once the list has re-read, so the row it closes onto already
      // says what was just saved.
      refreshThen(() => {
        toast.success(t("toast.updated", { domain: domain.domain }));
        onOpenChange?.(false);
      });
    } catch (error) {
      handleValidationError(error, form);
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
        {/*
          * The name, shown and not editable — with the reason.
          *
          * A disabled box with no explanation reads as a bug, and this one has
          * a real answer the user needs anyway: renaming is delete + add, and
          * they have to know that is the route rather than hunting for an
          * input that was never going to be here.
          */}
        <div className="space-y-2">
          <FormLabel className="text-muted-foreground">{t("edit.domain")}</FormLabel>
          <div className="flex items-center gap-2 rounded-lg border bg-muted/40 px-3 py-2">
            <Lock className="size-3.5 shrink-0 text-muted-foreground" aria-hidden />
            <span className="min-w-0 font-mono text-sm break-all">{domain?.domain}</span>
          </div>
          <p className="text-xs text-muted-foreground">{t("edit.domainLocked")}</p>
        </div>

        <FormField
          control={form.control}
          name="type"
          render={({ field }) => (
            <FormItem>
              <FormLabel hint={t("add.typeHint")}>{t("add.type")}</FormLabel>
              <Select value={field.value} onValueChange={field.onChange}>
                <FormControl>
                  <SelectTrigger className="w-full">
                    <SelectValue />
                  </SelectTrigger>
                </FormControl>
                <SelectContent>
                  <SelectItem value="alias">{t("type.alias")}</SelectItem>
                  <SelectItem value="redirect">{t("type.redirect")}</SelectItem>
                </SelectContent>
              </Select>
              {/* Same sentences as the add form — most people pick "alias"
                  when they mean "redirect", and that is no less true when
                  they are changing their mind about it. */}
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

        {dropsTarget ? (
          <Caution size="md">{t("edit.clearsTarget", { target: domain.redirect_to })}</Caution>
        ) : null}
      </FormModal>
    </Form>
  );
}
