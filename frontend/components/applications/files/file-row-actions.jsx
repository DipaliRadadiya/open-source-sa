import { useTranslations } from "next-intl";
import { toast } from "sonner";
import { MoreHorizontal, Download, ClipboardCopy } from "lucide-react";
import { fileDownloadUrl } from "@/lib/api/files";
import { Button } from "@/components/ui/button";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { FileActionItems } from "@/components/applications/files/file-actions-menu";

// A plain descriptive tooltip that also works when the button inside it is
// disabled — when `disabledReason` is set the tooltip explains that instead.
// A disabled native button fires neither hover nor focus events (same reason
// MenuItemHint/ReasonTooltip wrap their disabled children in a span), so the
// trigger is this inline-flex span, not the button itself.
function IconTooltip({ label, disabledReason, children }) {
  return (
    <Tooltip>
      <TooltipTrigger asChild>
        <span tabIndex={0} className="inline-flex">
          {children}
        </span>
      </TooltipTrigger>
      <TooltipContent>{disabledReason ?? label}</TooltipContent>
    </Tooltip>
  );
}

export function FileRowActions({ file, appId, canManage, onAction }) {
  const t = useTranslations("applications.files");
  const tc = useTranslations("common");
  // Refused everywhere in the API except delete — so every other action is
  // shown disabled with why, not hidden, matching the app's "never a silent
  // gap" convention (same as a locked log source, or a conflicting preset).
  const symlink = file.type === "symlink";
  const symlinkReason = symlink ? t("symlinkHint") : null;
  const canWrite = canManage;
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
    <div className="flex items-center justify-end gap-0.5">
      {/* The two safe, single-click, no-dialog actions sit directly on the
          row — everything else stays behind "…" so a glance at the row
          doesn't read as seven equally-weighted buttons, and nothing
          destructive is one accidental click away. */}
      {file.type !== "dir" ? (
        <IconTooltip label={t("actions.download")} disabledReason={downloadReason}>
          <Button variant="ghost" size="icon" className="size-8" disabled={symlink} asChild>
            <a href={fileDownloadUrl(appId, file.path)} download={file.name} aria-label={t("actions.download")}>
              <Download className="size-4" />
            </a>
          </Button>
        </IconTooltip>
      ) : null}
      <IconTooltip label={t("actions.copyPath")}>
        <Button
          variant="ghost"
          size="icon"
          className="size-8"
          onClick={copyPath}
          aria-label={t("actions.copyPath")}
        >
          <ClipboardCopy className="size-4" />
        </Button>
      </IconTooltip>

      {canWrite ? (
        <DropdownMenu>
          <DropdownMenuTrigger asChild>
            <Button variant="ghost" size="icon" className="size-8">
              <MoreHorizontal className="size-4" />
              <span className="sr-only">{t("actions.label")}</span>
            </Button>
          </DropdownMenuTrigger>
          <DropdownMenuContent align="end" className="w-48" onCloseAutoFocus={(e) => e.preventDefault()}>
            <FileActionItems
              file={file}
              appId={appId}
              canManage={canManage}
              onAction={onAction}
              Item={DropdownMenuItem}
              Separator={DropdownMenuSeparator}
              showQuickActions={false}
            />
          </DropdownMenuContent>
        </DropdownMenu>
      ) : null}
    </div>
  );
}
