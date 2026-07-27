"use client";

import { useState } from "react";
import { MoreHorizontal, KeyRound, KeySquare, Trash2 } from "lucide-react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import { SystemUserPasswordDialog } from "@/components/system-users/system-user-password-dialog";
import { SshKeysDialog } from "@/components/system-users/ssh-keys-dialog";
import { DeleteSystemUserDialog } from "@/components/system-users/delete-system-user-dialog";

export function SystemUserRowActions({ user }) {
  const t = useTranslations("systemUsers");
  const [pwOpen, setPwOpen] = useState(false);
  const [keysOpen, setKeysOpen] = useState(false);
  const [delOpen, setDelOpen] = useState(false);
  // Backend blocks deleting a user that still owns applications (422).
  const ownsApps = (user.applications?.length ?? 0) > 0;

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
          <DropdownMenuItem onSelect={() => setPwOpen(true)}>
            <KeyRound className="size-4" />
            {t("password.open")}
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => setKeysOpen(true)}>
            <KeySquare className="size-4" />
            {t("sshKeysAction")}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <MenuItemHint hint={ownsApps ? t("actions.deleteAppsHint") : null}>
            <DropdownMenuItem
              variant="destructive"
              disabled={ownsApps}
              onSelect={() => setDelOpen(true)}
            >
              <Trash2 className="size-4" />
              {t("actions.delete")}
            </DropdownMenuItem>
          </MenuItemHint>
        </DropdownMenuContent>
      </DropdownMenu>

      <SystemUserPasswordDialog user={user} open={pwOpen} onOpenChange={setPwOpen} />
      <SshKeysDialog user={user} open={keysOpen} onOpenChange={setKeysOpen} />
      <DeleteSystemUserDialog user={user} open={delOpen} onOpenChange={setDelOpen} />
    </div>
  );
}
