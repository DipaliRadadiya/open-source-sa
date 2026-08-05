"use client";

import { useEffect, useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, TriangleAlert } from "lucide-react";
import { roleFormSchema } from "@/lib/schemas/role";
import { createRole, updateRole } from "@/lib/api/roles";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { PermissionMatrix, permKey } from "@/components/admin/roles/permission-matrix";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import {
  Card,
  CardContent,
  CardHeader,
  CardTitle,
  CardDescription,
} from "@/components/ui/card";
import {
  Form,
  FormField,
  FormItem,
  FormLabel,
  FormControl,
  FormMessage,
} from "@/components/ui/form";

function seedMatrix(role) {
  const value = {};
  for (const p of role?.permissions ?? []) {
    value[permKey(p.level, p.name)] = {
      view: Boolean(p.permissions?.view),
      manage: Boolean(p.permissions?.manage),
    };
  }
  return value;
}

export function RoleForm({ mode = "create", role, catalog }) {
  const t = useTranslations("roles");
  const router = useRouter();
  const isEdit = mode === "edit";

  const [matrix, setMatrix] = useState(() => seedMatrix(role));
  const [initialMatrix] = useState(() => seedMatrix(role));
  const [confirmLeave, setConfirmLeave] = useState(false);
  const [justSubmitted, setJustSubmitted] = useState(false);

  const form = useForm({
    resolver: zodResolver(roleFormSchema),
    defaultValues: {
      name: role?.name ?? "",
      description: role?.description ?? "",
    },
  });

  const enabledCount = catalog.filter((item) => {
    const s = matrix[permKey(item.level, item.name)];
    return s && (s.view || s.manage);
  }).length;

  // A stable fingerprint of the granted permissions, so we can tell whether the
  // matrix drifted from what we loaded.
  const canonMatrix = (m) =>
    catalog
      .map((i) => {
        const s = m[permKey(i.level, i.name)] ?? {};
        return `${i.level}:${i.name}:${s.view ? 1 : 0}${s.manage ? 1 : 0}`;
      })
      .join(",");

  const isDirty =
    !justSubmitted &&
    (form.formState.isDirty ||
      canonMatrix(matrix) !== canonMatrix(initialMatrix));

  // Warn on hard navigation (tab close / refresh / external link) while dirty.
  useEffect(() => {
    if (!isDirty) return;
    const handler = (e) => {
      e.preventDefault();
      e.returnValue = "";
    };
    window.addEventListener("beforeunload", handler);
    return () => window.removeEventListener("beforeunload", handler);
  }, [isDirty]);

  function handleCancel() {
    if (isDirty) {
      setConfirmLeave(true);
    } else {
      router.push("/admin/roles");
    }
  }

  async function onSubmit(values) {
    const permissions = catalog.map((item) => {
      const s = matrix[permKey(item.level, item.name)] ?? { view: false, manage: false };
      return { level: item.level, name: item.name, view: s.view, manage: s.manage };
    });

    const payload = {
      name: values.name,
      description: values.description || null,
      permissions,
    };

    try {
      if (isEdit) {
        await updateRole(role.id, payload);
        toast.success(t("toast.updated"));
      } else {
        await createRole(payload);
        toast.success(t("toast.created"));
      }
      setJustSubmitted(true);
      router.push("/admin/roles");
      router.refresh();
    } catch (error) {
      handleValidationError(error, form);
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  return (
    <Form {...form}>
      <form
        onSubmit={form.handleSubmit(onSubmit, () => scrollToFirstError())}
        className="space-y-6"
      >
        <Card>
          <CardHeader>
            <CardTitle>{t("form.details")}</CardTitle>
          </CardHeader>
          <CardContent className="grid items-start gap-4 sm:grid-cols-2">
            <FormField
              control={form.control}
              name="name"
              render={({ field }) => (
                <FormItem>
                  <FormLabel required>{t("form.name")}</FormLabel>
                  <FormControl>
                    <Input placeholder={t("form.namePlaceholder")} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
            <FormField
              control={form.control}
              name="description"
              render={({ field }) => (
                <FormItem>
                  <FormLabel>{t("form.description")}</FormLabel>
                  <FormControl>
                    <Input placeholder={t("form.descriptionPlaceholder")} {...field} />
                  </FormControl>
                  <FormMessage />
                </FormItem>
              )}
            />
          </CardContent>
        </Card>

        <Card>
          <CardHeader className="flex flex-row items-start justify-between gap-4 space-y-0">
            <div className="space-y-1">
              <CardTitle>{t("form.permissions")}</CardTitle>
              <CardDescription>{t("form.permissionsHint")}</CardDescription>
            </div>
            <Badge
              variant={enabledCount > 0 ? "success" : "secondary"}
              className="shrink-0"
            >
              {t("form.enabledCount", { count: enabledCount, total: catalog.length })}
            </Badge>
          </CardHeader>
          <CardContent>
            <PermissionMatrix catalog={catalog} value={matrix} onChange={setMatrix} />
          </CardContent>
        </Card>

        <div className="flex items-center justify-end gap-2">
          <Button
            type="button"
            variant="outline"
            onClick={handleCancel}
            disabled={isSubmitting}
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
        </div>
      </form>

      <ConfirmDialog
        open={confirmLeave}
        onOpenChange={setConfirmLeave}
        icon={TriangleAlert}
        tone="destructive"
        title={t("form.unsavedTitle")}
        description={t("form.unsavedDescription")}
        cancelLabel={t("form.unsavedStay")}
        confirmLabel={t("form.unsavedLeave")}
        onConfirm={() => router.push("/admin/roles")}
      />
    </Form>
  );
}
