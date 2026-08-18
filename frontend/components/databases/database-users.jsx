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
 * The panel's own account. It manages every database on the server, so removing
 * it through this list would break the feature with no way back through the UI
 * — the API refuses, and the row says so before the click rather than after.
 */
const PANEL_PREFIX = "panel_";

const ACCESS_TONE = {
  localhost: "success",
  remote: "warning",
  anywhere: "destructive",
};

/**
 * Who can sign in to this database, and how.
 *
 * A database with no users is not finished — nothing can connect to it — so the
 * empty state here is a prompt rather than a shrug.
 */
export function DatabaseUsers({ database, canManage }) {
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
          {/* flex-wrap + a real minimum on the text: without them the
              heading block shrank to make room for the button and the sentence
              squeezed to three words a line. The button drops to its own row
              instead. */}
          <div className="flex flex-wrap items-center justify-between gap-3 border-b px-5 py-3.5">
            <div className="flex min-w-40 flex-1 items-center gap-2.5">
              <span className="flex size-7 shrink-0 items-center justify-center rounded-md bg-primary/10 text-primary">
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
            />
            {editing ? (
              <EditUserDialog
                database={database}
                user={editing}
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

  // No flex-wrap: on a phone the left column is wide enough to push the actions
  // button onto a line of its own, where it read as belonging to nothing.
  // min-w-0 on the text side lets it shrink instead.
  return (
    <div className="flex items-start justify-between gap-3 py-3.5">
      <div className="min-w-0 space-y-1.5">
        <div className="flex flex-wrap items-center gap-2">
          <span className="min-w-0 font-mono text-sm font-medium break-all">{user.username}</span>
          {/* Config files want the username on its own, not carved out of the
              connection string. */}
          <CopyButton value={user.username} label={t("copyUsername")} />

          {/* `anywhere` opens the engine port to the whole internet, so it is
              the one value worth colouring rather than stating flatly. */}
          {/* Colour carries the risk: local is safe, one address opens a
              firewall hole, anywhere opens it to the internet. A row of
              identical grey badges made all three look the same. */}
          <Badge variant={ACCESS_TONE[access] ?? "secondary"} className="font-normal">
            {tAccess(`${access}.label`)}
            {access === "remote" && user.host ? ` · ${user.host}` : ""}
          </Badge>

          {isPanel ? (
            <Badge variant="outline" className="font-normal">
              {t("panelAccount")}
            </Badge>
          ) : null}
        </div>

        {/* The line people actually came for. Copy rather than display: it
            carries the password, so it should be moved, not read aloud. */}
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
        ) : null}
      </div>

      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8 shrink-0" disabled={!canManage}>
            <MoreHorizontal className="size-4" />
            <span className="sr-only">{t("actions")}</span>
          </Button>
        </DropdownMenuTrigger>
        {/* Wide enough that "Change password" stays on one line — the menu was
            wrapping it across two. */}
        <DropdownMenuContent align="end" className="min-w-44">
          <DropdownMenuItem onClick={onPassword}>
            <KeyRound className="size-4" />
            {t("changePassword")}
          </DropdownMenuItem>
          <DropdownMenuItem onClick={onEdit}>
            <Pencil className="size-4" />
            {t("edit")}
          </DropdownMenuItem>
          {/* The panel's own account can't be removed. Same shape as the admin
              and system-user menus: the item keeps its normal label and a
              tooltip on the wrapper carries the reason — putting the reason IN
              the label made it too long for the menu, and it was clipped. */}
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
