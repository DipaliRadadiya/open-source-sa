import { useState } from "react";
import Link from "next/link";
import { MoreHorizontal, Pencil, Trash2 } from "lucide-react";
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
import { DeleteRoleDialog } from "@/components/admin/roles/delete-role-dialog";

export function RoleRowActions({ role }) {
  const t = useTranslations("roles");
  const [deleteOpen, setDeleteOpen] = useState(false);
  // System roles (e.g. Administrator) are protected — the backend rejects
  // edit/delete with a 422, so we disable both here.
  const isSystem = role.is_system;

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
          className="w-40"
          onCloseAutoFocus={(e) => e.preventDefault()}
        >
          {isSystem ? (
            <MenuItemHint hint={t("actions.systemHint")}>
              <DropdownMenuItem disabled>
                <Pencil className="size-4" />
                {t("actions.edit")}
              </DropdownMenuItem>
            </MenuItemHint>
          ) : (
            <DropdownMenuItem asChild>
              <Link href={`/admin/roles/${role.id}`}>
                <Pencil className="size-4" />
                {t("actions.edit")}
              </Link>
            </DropdownMenuItem>
          )}
          <DropdownMenuSeparator />
          <MenuItemHint hint={isSystem ? t("actions.systemHint") : null}>
            <DropdownMenuItem
              variant="destructive"
              disabled={isSystem}
              onSelect={() => setDeleteOpen(true)}
            >
              <Trash2 className="size-4" />
              {t("actions.delete")}
            </DropdownMenuItem>
          </MenuItemHint>
        </DropdownMenuContent>
      </DropdownMenu>

      {!isSystem && (
        <DeleteRoleDialog role={role} open={deleteOpen} onOpenChange={setDeleteOpen} />
      )}
    </div>
  );
}
