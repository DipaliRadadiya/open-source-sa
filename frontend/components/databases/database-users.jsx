"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import {
  KeyRound,
  MoreHorizontal,
  Pencil,
  Plus,
  Trash2,
  Users,
} from "lucide-react";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CopyButton } from "@/components/ui/copy-button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { AddUserDialog } from "@/components/databases/add-user-dialog";
import { EditUserDialog } from "@/components/databases/edit-user-dialog";
import { UserPasswordDialog } from "@/components/databases/user-password-dialog";
import { DeleteUserDialog } from "@/components/databases/delete-user-dialog";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";

/**
 * The panel's own account manages every database; the API refuses to remove
 * it, and the row says so before the click.
 */
const PANEL_PREFIX = "panel_";

const ACCESS_TONE = {
  localhost: "success",
  remote: "warning",
  anywhere: "destructive",
};

/**
 * Who can sign in to this database, and how. The empty state prompts to add a
 * user, since nothing can connect without one.
 */
export function DatabaseUsers({ database, canManage, remoteUsers = true }) {
  const t = useTranslations("databases.users");
  const [adding, setAdding] = useState(false);
  const [editing, setEditing] = useState(null);
  const [changing, setChanging] = useState(null);
  const [deleting, setDeleting] = useState(null);

  const users = database?.users ?? [];

  const addButton = (
    <ReasonTooltip reason={canManage ? null : t("noPermission")}>
      <Button disabled={!canManage} onClick={() => setAdding(true)}>
        <Plus className="size-4" />
        {t("add")}
      </Button>
    </ReasonTooltip>
  );

  return (
    <DisabledReasonProvider reason={canManage ? null : t("noPermission")}>
      <>
        <Card className="gap-0 overflow-hidden py-0">
          {/* flex-wrap plus a minimum width on the text, so the button drops to
              its own row instead of squeezing the sentence. */}
          <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
            <div className="flex min-w-40 flex-1 items-center gap-2.5">
              <span className="flex shrink-0 items-center justify-center text-muted-foreground">
                <Users className="size-3.5" />
              </span>
              <div>
                <h2 className="text-base font-semibold tracking-tight">
                  {t("title")}
                </h2>
                <p className="text-sm text-muted-foreground">{t("description")}</p>
              </div>
            </div>
            {users.length > 0 ? addButton : null}
          </div>
  
          <CardContent className="px-5 py-0">
            {users.length === 0 ? (
              <div className="flex flex-col items-center gap-3 py-10 text-center">
                <p className="text-sm font-medium">{t("empty.title")}</p>
                <p className="max-w-sm text-sm text-muted-foreground">
                  {t("empty.description")}
                </p>
                {addButton}
              </div>
            ) : (
              <div className="divide-y">
                {users.map((user) => (
                  <UserRow
                    key={user.id}
                    user={user}
                    canManage={canManage}
                    onEdit={() => setEditing(user)}
                    onPassword={() => setChanging(user)}
                    onDelete={() => setDeleting(user)}
                  />
                ))}
              </div>
            )}
          </CardContent>
        </Card>
  
        {canManage ? (
          <>
            <AddUserDialog
              database={database}
              open={adding}
              onOpenChange={setAdding}
              remoteUsers={remoteUsers}
            />
            {editing ? (
              <EditUserDialog
                database={database}
                user={editing}
                remoteUsers={remoteUsers}
                open
                onOpenChange={(next) => !next && setEditing(null)}
              />
            ) : null}
            {changing ? (
              <UserPasswordDialog
                database={database}
                user={changing}
                open
                onOpenChange={(next) => !next && setChanging(null)}
              />
            ) : null}
            {deleting ? (
              <DeleteUserDialog
                database={database}
                user={deleting}
                open
                onOpenChange={(next) => !next && setDeleting(null)}
              />
            ) : null}
          </>
        ) : null}
      </>
    </DisabledReasonProvider>
  );
}

function UserRow({ user, canManage, onEdit, onPassword, onDelete }) {
  const t = useTranslations("databases.users");
  const tAccess = useTranslations("databases.access");
  const isPanel = user.username.startsWith(PANEL_PREFIX);

  const access = user.connection_preference ?? "localhost";

  // No flex-wrap: on a phone the actions button would drop onto its own line.
  // min-w-0 on the text side lets it shrink instead.
  return (
    <div className="flex items-start justify-between gap-3 py-3.5">
      <div className="min-w-0 space-y-1.5">
        <div className="flex flex-wrap items-center gap-2">
          <span className="min-w-0 font-mono text-sm font-medium break-all">{user.username}</span>
          {/* Config files want the username on its own. */}
          <CopyButton value={user.username} label={t("copyUsername")} />

          {/* Colour carries the risk: local is safe, one address opens a
              firewall hole, `anywhere` opens the port to the internet. */}
          <Badge variant={ACCESS_TONE[access] ?? "muted"} className="font-normal">
            {tAccess(`${access}.label`)}
            {access === "remote" && user.host ? ` · ${user.host}` : ""}
          </Badge>

          {isPanel ? (
            <Badge variant="outline" className="font-normal">
              {t("panelAccount")}
            </Badge>
          ) : null}
        </div>

        {/* Copy rather than display: the string carries the password. */}
        {user.connection_string ? (
          <div className="flex items-center gap-1.5">
            <code className="truncate font-mono text-xs text-muted-foreground">
              {user.connection_string.replace(/:[^:@/]*@/, ":••••••@")}
            </code>
            <CopyButton
              value={user.connection_string}
              label={t("copyConnection")}
            />
          </div>
        ) : user.password_known === false ? (
          /*
             The API withholds the connection string for users adopted from a
             migrated server: the engine only keeps a hash.
          */
          <p className="text-xs text-muted-foreground">{t("passwordUnknown")}</p>
        ) : !canManage && user.password_known ? (
          // Withheld from a role without `database` manage.
          <p className="text-xs text-muted-foreground">{t("passwordWithheld")}</p>
        ) : null}
      </div>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8 shrink-0" disabled={!canManage}>
            <MoreHorizontal className="size-4" />
            <span className="sr-only">{t("actions")}</span>
          </Button>
        </DropdownMenuTrigger>
        {/* Wide enough that "Change password" stays on one line. */}
        <DropdownMenuContent align="end" className="min-w-44">
          <DropdownMenuItem onClick={onPassword}>
            <KeyRound className="size-4" />
            {t("changePassword")}
          </DropdownMenuItem>
          <DropdownMenuItem onClick={onEdit}>
            <Pencil className="size-4" />
            {t("edit")}
          </DropdownMenuItem>
          {/* The panel's own account can't be removed. As in the admin and
              system-user menus, the reason is a tooltip, not the label (which
              would be clipped). */}
          <MenuItemHint hint={isPanel ? t("cannotDeletePanel") : null}>
            <DropdownMenuItem
              variant="destructive"
              disabled={isPanel}
              onClick={onDelete}
            >
              <Trash2 className="size-4" />
              {t("delete")}
            </DropdownMenuItem>
          </MenuItemHint>
        </DropdownMenuContent>
      </DropdownMenu>
    </div>
  );
}
