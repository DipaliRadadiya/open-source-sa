"use client";

import { useEffect, useRef } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, ShieldCheck, UserRoundPlus, UserRoundPen, KeyRound } from "lucide-react";
import { createUserSchema, updateUserSchema } from "@/lib/schemas/user";
import { createUser, updateUser, syncUserRoles } from "@/lib/api/users";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { RolesField } from "@/components/admin/users/roles-field";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { PasswordInput } from "@/components/ui/password-input";
import { Checkbox } from "@/components/ui/checkbox";
import { Separator } from "@/components/ui/separator";
import { FormModal } from "@/components/ui/form-modal";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
  FormDescription,
} from "@/components/ui/form";

export function UserFormDialog({ mode = "create", user, roles = [], open, onOpenChange }) {
  const t = useTranslations("users");
  const router = useRouter();
  const isEdit = mode === "edit";
  const didAutoFocus = useRef(false);

  const form = useForm({
    resolver: zodResolver(isEdit ? updateUserSchema : createUserSchema),
    defaultValues: isEdit
      ? {
          name: user?.name ?? "",
          username: user?.username ?? "",
          is_admin: Boolean(user?.is_admin),
          role_ids: (user?.roles ?? []).map((r) => r.id),
        }
      : {
          name: "",
          username: "",
          password: "",
          password_confirmation: "",
          is_admin: false,
          role_ids: [],
        },
  });

  // Reset the "first focus" guard each time the modal opens.
  useEffect(() => {
    if (open) didAutoFocus.current = false;
  }, [open]);

  // Re-seed the edit form whenever it opens for a (possibly different) user.
  useEffect(() => {
    if (open && isEdit && user) {
      form.reset({
        name: user.name,
        username: user.username,
        is_admin: Boolean(user.is_admin),
        role_ids: (user.roles ?? []).map((r) => r.id),
      });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, user?.id]);

  async function onSubmit(values) {
    try {
      if (isEdit) {
        await updateUser(user.id, {
          name: values.name,
          username: values.username,
          is_admin: values.is_admin,
        });
        await syncUserRoles(user.id, values.role_ids);
        toast.success(t("toast.updated"));
      } else {
        await createUser(values);
        toast.success(t("toast.created"));
      }
      onOpenChange?.(false);
      form.reset();
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  // Clear values + validation errors when the modal closes.
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
        onSubmit={form.handleSubmit(onSubmit)}
        icon={isEdit ? UserRoundPen : UserRoundPlus}
        title={isEdit ? t("form.editTitle") : t("form.createTitle")}
        description={
          isEdit ? t("form.editDescription") : t("form.createDescription")
        }
        footer={
          <>
            <Button
              type="button"
              variant="outline"
              disabled={isSubmitting}
              onClick={() => handleOpenChange(false)}
            >
              {t("form.cancel")}
            </Button>
            <Button type="submit" disabled={isSubmitting}>
              {isSubmitting && <Loader2 className="size-4 animate-spin" />}
              {isSubmitting
                ? t("form.saving")
                : isEdit
                  ? t("form.submitEdit")
                  : t("form.submitCreate")}
            </Button>
          </>
        }
      >
        <div className="grid gap-5">
          {/* Identity */}
              <div className="grid gap-4 sm:grid-cols-2">
                <FormField
                  control={form.control}
                  name="name"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t("form.name")}</FormLabel>
                      <FormControl>
                        <Input
                          placeholder={t("form.namePlaceholder")}
                          autoComplete="off"
                          {...field}
                          onFocus={(e) => {
                            // On the modal's auto-focus, the browser selects the
                            // pre-filled value. Collapse it to the end, once, so
                            // later clicks/tabs behave normally. (The dropdown's
                            // focus-restore is prevented separately, or it would
                            // re-select after this.)
                            if (didAutoFocus.current) return;
                            didAutoFocus.current = true;
                            const el = e.currentTarget;
                            requestAnimationFrame(() => {
                              try {
                                const len = el.value.length;
                                el.setSelectionRange(len, len);
                              } catch {}
                            });
                          }}
                        />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
                <FormField
                  control={form.control}
                  name="username"
                  render={({ field }) => (
                    <FormItem>
                      <FormLabel>{t("form.username")}</FormLabel>
                      <FormControl>
                        <Input placeholder={t("form.usernamePlaceholder")} autoComplete="off" {...field} />
                      </FormControl>
                      <FormMessage />
                    </FormItem>
                  )}
                />
              </div>

              {!isEdit && (
                <div className="grid gap-4 sm:grid-cols-2">
                  <FormField
                    control={form.control}
                    name="password"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>{t("form.password")}</FormLabel>
                        <FormControl>
                          <PasswordInput
                            placeholder={t("form.passwordPlaceholder")}
                            autoComplete="new-password"
                            {...field}
                          />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                  <FormField
                    control={form.control}
                    name="password_confirmation"
                    render={({ field }) => (
                      <FormItem>
                        <FormLabel>{t("form.confirmPassword")}</FormLabel>
                        <FormControl>
                          <PasswordInput
                            placeholder={t("form.confirmPasswordPlaceholder")}
                            autoComplete="new-password"
                            {...field}
                          />
                        </FormControl>
                        <FormMessage />
                      </FormItem>
                    )}
                  />
                </div>
              )}

              <Separator />
              <div className="flex items-center gap-2">
                <span className="flex size-6 items-center justify-center rounded-md bg-primary/10 text-primary">
                  <KeyRound className="size-3.5" />
                </span>
                <h3 className="text-sm font-semibold">{t("form.sectionAccess")}</h3>
              </div>

              <FormField
                control={form.control}
                name="role_ids"
                render={({ field }) => (
                  <FormItem className="space-y-2">
                    <div className="space-y-1">
                      <FormLabel>{t("form.roles")}</FormLabel>
                      <FormDescription>{t("form.rolesHint")}</FormDescription>
                    </div>
                    <FormControl>
                      <RolesField roles={roles} value={field.value} onChange={field.onChange} />
                    </FormControl>
                    <FormMessage />
                  </FormItem>
                )}
              />

              <FormField
                control={form.control}
                name="is_admin"
                render={({ field }) => (
                  <FormItem className="space-y-2">
                    <div className="space-y-1">
                      <FormLabel>{t("form.isAdmin")}</FormLabel>
                      <FormDescription>{t("form.isAdminHint")}</FormDescription>
                    </div>
                    <label className="flex cursor-pointer items-center gap-3 rounded-lg border px-3 py-2.5 transition-colors hover:bg-muted/50">
                      <FormControl>
                        <Checkbox
                          checked={field.value}
                          onCheckedChange={(c) => field.onChange(c === true)}
                        />
                      </FormControl>
                      <ShieldCheck className="size-4 shrink-0 text-primary" />
                      <span className="text-sm font-medium">
                        {t("form.isAdminToggle")}
                      </span>
                    </label>
                  </FormItem>
                )}
              />
        </div>
      </FormModal>
    </Form>
  );
}
