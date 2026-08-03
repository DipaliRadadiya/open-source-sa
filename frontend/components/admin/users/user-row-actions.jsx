"use client";

import { useState } from "react";
import { toast } from "sonner";
import {
  MoreHorizontal,
  Pencil,
  KeyRound,
  Trash2,
  UserRoundCog,
} from "lucide-react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { ConfirmDialog } from "@/components/ui/confirm-dialog";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import { UserFormDialog } from "@/components/admin/users/user-form-dialog";
import { ResetPasswordDialog } from "@/components/admin/users/reset-password-dialog";
import { DeleteUserDialog } from "@/components/admin/users/delete-user-dialog";
import { impersonateUser } from "@/lib/api/users";
import { apiMessage } from "@/lib/api/error-message";

export function UserRowActions({ user, roles = [], currentUserId }) {
  const t = useTranslations("users");
  const [editOpen, setEditOpen] = useState(false);
  const [resetOpen, setResetOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [impersonateOpen, setImpersonateOpen] = useState(false);
  const [impersonating, setImpersonating] = useState(false);
  const isSelf = user.id === currentUserId;
  // Backend blocks self and admin→admin (422) — mirror that in the UI.
  const canImpersonate = !isSelf && !user.is_admin;
  const impersonateHint = isSelf
    ? t("actions.impersonateSelfHint")
    : user.is_admin
      ? t("actions.impersonateAdminHint")
      : null;

  async function onImpersonate() {
    setImpersonating(true);
    try {
      await impersonateUser(user.id);
      // Session identity changed — hard-navigate so SSR re-reads the new
      // cookie (router.refresh would leave stale server-rendered chrome).
      window.location.href = "/dashboard";
    } catch (error) {
      toast.error(apiMessage(error, t("actions.impersonateFailed")));
      setImpersonating(false);
    }
  }

  return (
    <div className="text-right">
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8">
            <MoreHorizontal className="size-4" />
            <span className="sr-only">{t("actions.label")}</span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
          align="end"
          className="w-44"
          onCloseAutoFocus={(e) => e.preventDefault()}
        >
          <DropdownMenuItem onSelect={() => setEditOpen(true)}>
            <Pencil className="size-4" />
            {t("actions.edit")}
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => setResetOpen(true)}>
            <KeyRound className="size-4" />
            {t("actions.resetPassword")}
          </DropdownMenuItem>
          <MenuItemHint hint={impersonateHint}>
            <DropdownMenuItem
              disabled={!canImpersonate}
              onSelect={() => setImpersonateOpen(true)}
            >
              <UserRoundCog className="size-4" />
              {t("actions.impersonate")}
            </DropdownMenuItem>
          </MenuItemHint>
          <DropdownMenuSeparator />
          <MenuItemHint hint={isSelf ? t("actions.deleteSelfHint") : null}>
            <DropdownMenuItem
              variant="destructive"
              disabled={isSelf}
              onSelect={() => setDeleteOpen(true)}
            >
              <Trash2 className="size-4" />
              {t("actions.delete")}
            </DropdownMenuItem>
          </MenuItemHint>
        </DropdownMenuContent>
      </DropdownMenu>

      <UserFormDialog
        mode="edit"
        user={user}
        roles={roles}
        open={editOpen}
        onOpenChange={setEditOpen}
      />
      <ResetPasswordDialog user={user} open={resetOpen} onOpenChange={setResetOpen} />
      <DeleteUserDialog user={user} open={deleteOpen} onOpenChange={setDeleteOpen} />
      <ConfirmDialog
        open={impersonateOpen}
        onOpenChange={setImpersonateOpen}
        icon={UserRoundCog}
        tone="default"
        title={t("impersonate.title")}
        description={t("impersonate.description", { name: user.name })}
        cancelLabel={t("impersonate.cancel")}
        confirmLabel={
          impersonating ? t("impersonate.starting") : t("impersonate.confirm")
        }
        pending={impersonating}
        onConfirm={onImpersonate}
      />
    </div>
  );
}
