"use client";

import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, UserRoundPlus } from "lucide-react";
import { createSystemUserSchema } from "@/lib/schemas/system-user";
import { createSystemUser } from "@/lib/api/system-users";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Textarea } from "@/components/ui/textarea";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";

export function CreateSystemUserDialog({ open, onOpenChange, onCreated }) {
  const t = useTranslations("systemUsers");
  const router = useRouter();

  const form = useForm({
    resolver: zodResolver(createSystemUserSchema),
    defaultValues: { username: "", public_key: "" },
  });

  async function onSubmit(values) {
    const payload = { username: values.username };
    if (values.public_key?.trim()) payload.public_key = values.public_key.trim();
    try {
      const { data } = await createSystemUser(payload);
      toast.success(t("toast.created"));
      onCreated?.(data?.system_user ?? data?.user ?? null);
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
        icon={UserRoundPlus}
        title={t("create.title")}
        description={t("create.subtitle")}
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => handleOpenChange(false)}
            >
              {t("cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting ? t("saving") : t("create.submit")}
            </Button>
          </>
        }
      >
        <FormField
          control={form.control}
          name="username"
          render={({ field }) => (
            <FormItem>
              <FormLabel required>{t("create.username")}</FormLabel>
              <FormControl>
                <Input
                  placeholder={t("create.usernamePlaceholder")}
                  autoComplete="off"
                  className="font-mono"
                  {...field}
                />
              </FormControl>
              <FormMessage />
            </FormItem>
          )}
        />
        <FormField
          control={form.control}
          name="public_key"
          render={({ field }) => (
            <FormItem>
              <FormLabel>{t("create.publicKey")}</FormLabel>
              <FormControl>
                <Textarea
                  rows={3}
                  placeholder={t("create.publicKeyPlaceholder")}
                  className="font-mono text-xs"
                  {...field}
                />
              </FormControl>
              <p className="text-xs text-muted-foreground">
                {t("create.publicKeyHint")}
              </p>
              <FormMessage />
            </FormItem>
          )}
        />
      </FormModal>
    </Form>
  );
}
