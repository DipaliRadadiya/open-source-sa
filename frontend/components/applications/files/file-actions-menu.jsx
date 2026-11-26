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
  Trash2,
} from "lucide-react";
import { fileDownloadUrl } from "@/lib/api/files";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import { useServerPath } from "@/components/applications/files/files-root-context";
import { DENIED } from "@/components/ui/reason-tooltip";

const ARCHIVE_RE = /\.(zip|tar\.gz|tgz)$/i;

export function FileActionItems({
  file,
  appId,
  canManage,
  onAction,
  Item,
  Separator,
  showQuickActions = true,
}) {
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  const { toServerPath } = useServerPath();
  const symlink = file.type === "symlink";
  const symlinkReason = symlink ? t("symlinkHint") : null;
  const canWrite = canManage;
  const permReason = symlinkReason ?? (canWrite ? null : DENIED);
  const isArchive = file.type === "file" && ARCHIVE_RE.test(file.name);
  const downloadReason = symlinkReason ?? (canManage ? null : DENIED);

  async function copyPath() {
    try {
      await navigator.clipboard.writeText(toServerPath(file.path));
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
          <MenuItemHint hint={canWrite ? null : DENIED}>
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
