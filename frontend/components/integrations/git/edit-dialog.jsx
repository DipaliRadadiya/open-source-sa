import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { z } from "zod";
import { Loader2, Pencil } from "lucide-react";
import { labelSchema } from "@/lib/schemas/git";
import { updateAccount } from "@/lib/api/git";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
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

const schema = z.object({
  label: labelSchema,
  host: z.string().trim().optional(),
  workspace: z.string().trim().optional(),
});

/**
 * Renaming, and the two settings that are not secrets.
 *
 * The token is deliberately absent: swapping a credential is a verified
 * round-trip that can fail and leave you needing to know which token is live,
 * while renaming is instant and cannot break anything. Same endpoint, different
 * risk, so different dialogs.
 */
export function EditDialog({ account, open, onOpenChange }) {
  const t = useTranslations("git.edit");
  const router = useRouter();

  const form = useForm({
    resolver: zodResolver(schema),
    mode: "onSubmit",
    reValidateMode: "onChange",
    defaultValues: {
      label: account?.label ?? "",
      host: account?.host ?? "",
      workspace: account?.workspace ?? "",
    },
  });

  async function onSubmit(values) {
    const payload = { label: values.label };
    // Only sent where the provider has the concept at all.
    if (account.host !== null && account.host !== undefined) payload.host = values.host;
    if (account.workspace) payload.workspace = values.workspace;

    try {
      await updateAccount(account.id, payload);
      toast.success(t("saved"));
      router.refresh();
      onOpenChange?.(false);
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const submitting = form.formState.isSubmitting;

  return (
    <Form {...form}>
      <FormModal
        open={open}
        onOpenChange={onOpenChange}
        asForm
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        icon={Pencil}
        title={t("title")}
        description={t("subtitle")}
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
              {t("submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="label"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("nameLabel")}</FormLabel>
              <FormControl>
                <Input placeholder={t("namePlaceholder")} autoComplete="off" {...field} />
              </FormControl>
              <FormDescription>{t("nameHelp")}</FormDescription>
              <FormMessage field={t("nameLabel")} />
            </FormItem>
          )}
        />

        {account?.workspace ? (
          <FormField
            control={form.control}
            name="workspace"
            render={({ field }) => (
              <FormItem>
                <FormLabel>{t("workspaceLabel")}</FormLabel>
                <FormControl>
                  <Input
                    placeholder={t("workspacePlaceholder")}
                    autoComplete="off"
                    spellCheck={false}
                    {...field}
                  />
                </FormControl>
                <FormMessage field={t("workspaceLabel")} />
              </FormItem>
            )}
          />
        ) : null}

        {account?.provider === "gitlab" ? (
          <FormField
            control={form.control}
            name="host"
            render={({ field }) => (
              <FormItem>
                <FormLabel>{t("hostLabel")}</FormLabel>
                <FormControl>
                  <Input
                    placeholder="https://gitlab.example.com"
                    autoComplete="off"
                    spellCheck={false}
                    {...field}
                  />
                </FormControl>
                <FormDescription>{t("hostHelp")}</FormDescription>
                <FormMessage field={t("hostLabel")} />
              </FormItem>
            )}
          />
        ) : null}
      </FormModal>
    </Form>
  );
}
