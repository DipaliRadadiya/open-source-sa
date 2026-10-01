import { useRef, useState } from "react";
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

// Shown to viewers too: the menu is the only way to view a user's SSH keys.
// Actions they cannot take stay visible, disabled with the reason.
export function SystemUserRowActions({ user, canManage = true, prevPage = null }) {
  const t = useTranslations("systemUsers");
  const [pwOpen, setPwOpen] = useState(false);
  const [keysOpen, setKeysOpen] = useState(false);
  const [delOpen, setDelOpen] = useState(false);
  // Only an item that opens a dialog keeps focus off the ⋯ button (the dialog takes
  // it, then hands it back); Escape or a click away returns it there.
  const openingDialog = useRef(false);
  const open = (setter) => () => {
    openingDialog.current = true;
    setter(true);
  };
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
          onCloseAutoFocus={(e) => {
            if (!openingDialog.current) return;
            openingDialog.current = false;
            e.preventDefault();
          }}
        >
          <MenuItemHint hint={canManage ? null : t("noPermission")}>
            <DropdownMenuItem disabled={!canManage} onSelect={open(setPwOpen)}>
              <KeyRound className="size-4" />
              {t("password.open")}
            </DropdownMenuItem>
          </MenuItemHint>
          <DropdownMenuItem onSelect={open(setKeysOpen)}>
            <KeySquare className="size-4" />
            {t("sshKeysAction")}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <MenuItemHint
            hint={!canManage ? t("noPermission") : ownsApps ? t("actions.deleteAppsHint") : null}
          >
            <DropdownMenuItem
              variant="destructive"
              disabled={!canManage || ownsApps}
              onSelect={open(setDelOpen)}
            >
              <Trash2 className="size-4" />
              {t("actions.delete")}
            </DropdownMenuItem>
          </MenuItemHint>
        </DropdownMenuContent>
      </DropdownMenu>

      {canManage ? (
        <SystemUserPasswordDialog user={user} open={pwOpen} onOpenChange={setPwOpen} />
      ) : null}
      <SshKeysDialog user={user} open={keysOpen} onOpenChange={setKeysOpen} canManage={canManage} />
      {canManage ? (
        <DeleteSystemUserDialog user={user} open={delOpen} onOpenChange={setDelOpen} prevPage={prevPage} />
      ) : null}
    </div>
  );
}
