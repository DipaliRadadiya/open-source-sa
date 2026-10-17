"use client";

import { useState } from "react";
import { useForm } from "react-hook-form";
import { zodResolver } from "@hookform/resolvers/zod";
import { useRouter } from "next/navigation";
import { useRefresh } from "@/hooks/use-refresh";
import { toast } from "sonner";
import { useTranslations } from "next-intl";
import { Loader2, TriangleAlert } from "lucide-react";
import { roleFormSchema, ACCESS_NONE } from "@/lib/schemas/role";
import { createRole, updateRole } from "@/lib/api/roles";
import { handleValidationError } from "@/lib/api/handle-validation-error";
import { scrollToFirstError } from "@/lib/forms/scroll-to-first-error";
import { PermissionMatrix, permKey } from "@/components/admin/roles/permission-matrix";
import { countAccess } from "@/lib/roles/granted-count";
import { Button } from "@/components/ui/button";
import { Badge } from "@/components/ui/badge";
import { Input } from "@/components/ui/input";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { useWatchUnsaved } from "@/components/ui/unsaved-guard";
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

// The role's grants keyed the way the matrix reads them. The schema derives
// `access` from the old boolean pair when an older backend omits it.
function seedMatrix(role) {
  const value = {};
  for (const entry of role?.permissions ?? []) {
    value[permKey(entry.level, entry.name)] = entry.access ?? ACCESS_NONE;
  }
  return value;
}

export function RoleForm({ mode = "create", role, catalog }) {
  const t = useTranslations("roles");
  const tSettings = useTranslations("settings.common");
  const router = useRouter();
  const { pushAndWait } = useRefresh();
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

  const permissions = catalog.permissions ?? [];
  const counts = countAccess(permissions.map((item) => matrix[permKey(item.level, item.name)]));

  // Fingerprint of the granted permissions, to detect changes from what was loaded.
  const canonMatrix = (m) =>
    permissions
      .map((i) => `${i.level}:${i.name}:${m[permKey(i.level, i.name)] ?? ACCESS_NONE}`)
      .join(",");

  const isDirty =
    !justSubmitted &&
    (form.formState.isDirty ||
      canonMatrix(matrix) !== canonMatrix(initialMatrix));
  useWatchUnsaved("admin-role", isDirty);

  function handleCancel() {
    if (isDirty) {
      setConfirmLeave(true);
    } else {
      router.push("/admin/roles");
    }
  }

  async function onSubmit(values) {
    // One access level per permission; a boolean pair could express a state the
    // server does not store.
    const payload = {
      name: values.name,
      description: values.description || null,
      permissions: permissions.map((item) => ({
        level: item.level,
        name: item.name,
        access: matrix[permKey(item.level, item.name)] ?? ACCESS_NONE,
      })),
    };

    try {
      if (isEdit) await updateRole(role.id, payload);
      else await createRole(payload);
      setJustSubmitted(true);
      // Navigate first, then toast, so the form is not left up saying "saved".
      await pushAndWait("/admin/roles");
      toast.success(isEdit ? t("toast.updated") : t("toast.created"));
    } catch (error) {
      handleValidationError(error, form, { fallback: isEdit ? t("toast.updateFailed") : t("toast.createFailed") });
    }
  }

  const isSubmitting = form.formState.isSubmitting;

  return (
    <Form {...form}>
      <form noValidate
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
          {/* Wraps: beside the longer view · manage badge, the hint went one word per line on a phone. */}
          <CardHeader className="flex flex-row flex-wrap items-start justify-between gap-x-4 gap-y-2 space-y-0">
            <div className="min-w-48 flex-1 space-y-1">
              <CardTitle>{t("form.permissions")}</CardTitle>
              <CardDescription>{t("form.permissionsHint")}</CardDescription>
            </div>
            <Badge variant={counts.view > 0 ? "success" : "muted"} className="shrink-0">
              {t("accessCount", counts)}
            </Badge>
          </CardHeader>
          <CardContent>
            <PermissionMatrix
              groups={catalog.groups ?? []}
              value={matrix}
              onChange={setMatrix}
            />
          </CardContent>
        </Card>

        {/* Pinned to the bottom of the screen: below 38 permission rows, Save was three
            screens away (Krishna, 17 Oct). */}
        <div className="sticky bottom-0 z-10 -mx-1 flex flex-wrap items-center justify-end gap-2 border-t bg-background/95 px-1 py-3 backdrop-blur supports-[backdrop-filter]:bg-background/80">
          {isDirty ? <p className="mr-auto text-sm text-muted-foreground">{tSettings("unsaved")}</p> : null}
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
