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

// Shared by the row dropdown and context menu; their `Item`/`Separator` share one prop API.
export function FileActionItems({
  file,
  appId,
  canManage,
  onAction,
  Item,
  Separator,
  // The "…" dropdown already has Download/Copy path as icon buttons beside it; the
  // context menu has none, so it includes them here.
  showQuickActions = true,
}) {
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  const symlink = file.type === "symlink";
  const symlinkReason = symlink ? t("symlinkHint") : null;
  const canWrite = canManage;
  const permReason = symlinkReason ?? (canWrite ? null : t("noPermission"));
  const isArchive = file.type === "file" && ARCHIVE_RE.test(file.name);
  // Downloading reads the file, which needs File Manager manage.
  const downloadReason = symlinkReason ?? (canManage ? null : t("noPermission"));

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
              <Item disabled={Boolean(downloadReason)} asChild={!downloadReason}>
                {downloadReason ? (
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

      {/* Directories only: a file's size is already in the row. */}
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
