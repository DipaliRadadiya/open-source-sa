"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { DisabledReasonProvider } from "@/components/ui/reason-tooltip";
import { KeyRound, MoreHorizontal, Pencil, Plus, Trash2, Users } from "lucide-react";
import { EmptyState } from "@/components/data-table/empty-state";
import { Badge } from "@/components/ui/badge";
import { Button } from "@/components/ui/button";
import { Card, CardContent } from "@/components/ui/card";
import { CopyButton } from "@/components/ui/copy-button";
import { CardIcon } from "@/components/ui/card-icon";
import { ConnectionString } from "@/components/databases/connection-string";
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

/** The panel's own account; the API refuses to remove it. */
const PANEL_PREFIX = "panel_";

export const ACCESS_TONE = {
  localhost: "success",
  remote: "warning",
  anywhere: "destructive",
};

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
            <div className="flex min-w-40 flex-1 items-center gap-3">
              <CardIcon icon={Users} />
              <div>
                <h2 className="text-[15px] font-semibold tracking-tight">
                  {t("title")}
                </h2>
                <p className="text-sm text-muted-foreground">{t("description")}</p>
              </div>
            </div>
            {users.length > 0 ? addButton : null}
          </div>
  
          <CardContent className="px-5 py-0">
            {users.length === 0 ? (
              <div className="py-5">
                <EmptyState
                  compact
                  icon={Users}
                  title={t("empty.title")}
                  description={t("empty.description")}
                  action={addButton}
                />
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
  const tc = useTranslations("databases.credentials");
  const tAccess = useTranslations("databases.access");
  const isPanel = user.username.startsWith(PANEL_PREFIX);

  const access = user.connection_preference ?? "localhost";

  // No flex-wrap: on a phone the actions button would drop onto its own line.
  // min-w-0 on the text side lets it shrink instead.
  return (
    <div className="flex items-start gap-3 py-4">
      {/* The initial, so a list of similar generated names is easier to scan. */}
      <span
        aria-hidden
        className="mt-0.5 flex size-9 shrink-0 items-center justify-center rounded-full bg-primary/10 text-sm font-semibold text-primary uppercase"
      >
        {user.username.replace(PANEL_PREFIX, "").charAt(0) || "?"}
      </span>
      <div className="min-w-0 flex-1 space-y-2.5">
        <div className="flex flex-wrap items-center gap-2 pt-1.5">
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

        {/* Masked on screen; Copy carries the password. */}
        {user.connection_string ? (
          <div className="space-y-1.5">
            <p className="text-xs text-muted-foreground">{tc("connectionString")}</p>
            <ConnectionString value={user.connection_string} variant="inline" />
          </div>
        ) : user.password_known === false ? (
          /* Withheld for users adopted from a migrated server: the engine keeps only a hash. */
          <p className="text-xs text-muted-foreground">{t("passwordUnknown")}</p>
        ) : !canManage && user.password_known ? (
          // Withheld from a role without `database` manage.
          <p className="text-xs text-muted-foreground">{t("passwordWithheld")}</p>
        ) : null}
      </div>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="outline" size="icon" className="mt-0.5 size-8 shrink-0" disabled={!canManage}>
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
          {/* The reason is a tooltip, not the label (which would be clipped), as in the
              admin and system-user menus. */}
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
