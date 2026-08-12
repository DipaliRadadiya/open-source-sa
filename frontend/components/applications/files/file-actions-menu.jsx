"use client";

import { useTranslations } from "next-intl";
import { toast } from "sonner";
import {
  Download,
  ClipboardCopy,
  PencilLine,
  Copy,
  Archive,
  FolderOpen,
  Lock,
  Scale,
  Trash2,
} from "lucide-react";
import { fileDownloadUrl } from "@/lib/api/files";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";

const ARCHIVE_RE = /\.(zip|tar\.gz|tgz)$/i;

/**
 * The full action list for one file — shared between the row's "…" dropdown
 * and its right-click context menu, which need the exact same items and
 * rules but render through different menu primitives. `Item`/`Separator` are
 * passed in (DropdownMenuItem/DropdownMenuSeparator or
 * ContextMenuItem/ContextMenuSeparator) since both share an identical prop API.
 */
export function FileActionItems({
  file,
  appId,
  canManage,
  onAction,
  Item,
  Separator,
  // The row's own "…" dropdown already has Download/Copy path as standalone
  // icon buttons next to it — repeating them inside that menu too would just
  // be clutter. The right-click context menu has no such icons, so it needs
  // them folded in here instead.
  showQuickActions = true,
}) {
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  const symlink = file.type === "symlink";
  const symlinkReason = symlink ? t("symlinkHint") : null;
  const canWrite = canManage;
  const permReason = symlinkReason ?? (canWrite ? null : t("noPermission"));
  const isArchive = file.type === "file" && ARCHIVE_RE.test(file.name);
  const downloadReason = symlinkReason;

  async function copyPath() {
    try {
      await navigator.clipboard.writeText(file.path);
      toast.success(t("actions.pathCopied"));
    } catch {
      toast.error(tc("copyFailed"));
    }
  }

  return (
    <>
      {showQuickActions ? (
        <>
          {file.type !== "dir" ? (
            <MenuItemHint hint={downloadReason}>
              <Item disabled={symlink} asChild={!symlink}>
                {symlink ? (
                  <span className="flex items-center gap-1.5">
                    <Download className="size-4" />
                    {t("actions.download")}
                  </span>
                ) : (
                  <a href={fileDownloadUrl(appId, file.path)} download={file.name}>
                    <Download className="size-4" />
                    {t("actions.download")}
                  </a>
                )}
              </Item>
            </MenuItemHint>
          ) : null}
          <Item onSelect={copyPath}>
            <ClipboardCopy className="size-4" />
            {t("actions.copyPath")}
          </Item>
          {canWrite ? <Separator /> : null}
        </>
      ) : null}

      {/* Directories only — a file's size is already in the row, and asking
          the backend to walk a single file would be a request for a fact we
          were handed. */}
      {file.type === "dir" ? (
        <Item onSelect={() => onAction("size", file)}>
          <Scale className="size-4" />
          {t("actions.folderSize")}
        </Item>
      ) : null}

      {canWrite ? (
        <>
          <MenuItemHint hint={permReason}>
            <Item disabled={!canWrite || symlink} onSelect={() => onAction("rename", file)}>
              <PencilLine className="size-4" />
              {t("actions.rename")}
            </Item>
          </MenuItemHint>
          <MenuItemHint hint={permReason}>
            <Item disabled={!canWrite || symlink} onSelect={() => onAction("copy", file)}>
              <Copy className="size-4" />
              {t("actions.copy")}
            </Item>
          </MenuItemHint>
          <MenuItemHint hint={permReason}>
            <Item disabled={!canWrite || symlink} onSelect={() => onAction("compress", file)}>
              <Archive className="size-4" />
              {t("actions.compress")}
            </Item>
          </MenuItemHint>
          {isArchive ? (
            <MenuItemHint hint={permReason}>
              <Item disabled={!canWrite} onSelect={() => onAction("extract", file)}>
                <FolderOpen className="size-4" />
                {t("actions.extract")}
              </Item>
            </MenuItemHint>
          ) : null}
          <MenuItemHint hint={permReason}>
            <Item disabled={!canWrite || symlink} onSelect={() => onAction("permissions", file)}>
              <Lock className="size-4" />
              {t("actions.permissions")}
            </Item>
          </MenuItemHint>
          <Separator />
          <MenuItemHint hint={canWrite ? null : t("noPermission")}>
            <Item variant="destructive" disabled={!canWrite} onSelect={() => onAction("delete", file)}>
              <Trash2 className="size-4" />
              {t("actions.delete")}
            </Item>
          </MenuItemHint>
        </>
      ) : null}
    </>
  );
}
