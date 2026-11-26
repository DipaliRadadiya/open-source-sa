import Link from "@/components/ui/app-link";
import { Folder, Link2, Loader2, Unlink } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Checkbox } from "@/components/ui/checkbox";
import { FileRowActions } from "@/components/applications/files/file-row-actions";
import { FileThumb } from "@/components/applications/files/file-thumb";
import { isImageFile } from "@/lib/files/file-icon";
import { canOpenFile } from "@/lib/files/openable";
import { isWorldWritable, symbolicMode } from "@/lib/files/describe-mode";
import { FILE_NAME } from "@/lib/files/name-style";
import { measuredSize } from "@/lib/files/folder-sizes";
import { FileSize } from "@/components/applications/files/file-size";
import { HoverHint } from "@/components/ui/hover-hint";

export function FilesCards({
  appId,
  data,
  canManage,
  onAction,
  busyPath,
  highlightPath,
  selected = [],
  onToggle,
  folderSizes = null,
}) {
  const t = useTranslations("applications.files");
  return (
    <ul className="space-y-2">
      {data.map((file) => {
        const busy = busyPath === file.path;
        const measured = measuredSize(file, folderSizes);
        return (
          <li
            key={file.path}
            className={cn(
              "flex items-center justify-between gap-3 rounded-xl border bg-card p-3 transition-colors duration-700",
              file.type === "symlink" && "opacity-70",
              file.path === highlightPath && "bg-primary/10",
            )}
          >
            <div className="flex min-w-0 items-center gap-2.5">
              {canManage ? (
                <Checkbox
                  className="shrink-0"
                  checked={selected.includes(file.path)}
                  onCheckedChange={() => onToggle?.(file.path)}
                  aria-label={t("bulk.selectOne", { name: file.name })}
                />
              ) : null}
              {file.type === "dir" ? (
                <Folder className="size-4 shrink-0 text-primary" />
              ) : file.type === "symlink" ? (
                file.link_broken ? (
                  <Unlink className="size-4 shrink-0 text-destructive" />
                ) : (
                  <Link2 className="size-4 shrink-0 text-muted-foreground" />
                )
              ) : (
                <FileThumb file={file} appId={appId} className="size-5" canPreview={canManage} />
              )}
              <div className="min-w-0">
                {file.type === "dir" ? (
                  <HoverHint content={file.name}><Link
                    href={`/applications/${appId}/files?path=${encodeURIComponent(file.path)}`}
                    prefetch={false}
                    className={cn("block font-medium hover:underline", FILE_NAME)}
                  >
                    {file.name}
                  </Link></HoverHint>
                ) : file.type === "symlink" ? (
                  <HoverHint content={file.link_target ?? undefined}><span
                    className={cn(
                      "block truncate font-medium",
                      file.link_broken ? "text-destructive" : "text-muted-foreground",
                    )}
                  >
                    {file.name}
                    {file.link_target ? (
                      <span className="font-mono text-xs text-muted-foreground"> → {file.link_target}</span>
                    ) : null}
                  </span></HoverHint>
                ) : (
                  !canManage || !canOpenFile(file.name) ? (
                    <HoverHint content={file.name}><span className={cn("block w-full font-medium", FILE_NAME)}>
                      {file.name}
                    </span></HoverHint>
                  ) : (
                    <HoverHint content={file.name}><button
                      type="button"
                      onClick={() => onAction(isImageFile(file.name) ? "preview" : "edit", file)}
                      className={cn("block w-full text-left font-medium hover:underline", FILE_NAME)}
                    >
                      {file.name}
                    </button></HoverHint>
                  )
                )}
                <p className="text-xs leading-relaxed text-muted-foreground">
                  {file.type !== "dir" ? (
                    <FileSize bytes={file.size} className="whitespace-nowrap tabular-nums" />
                  ) : measured ? (
                    <FileSize bytes={measured.size} className="whitespace-nowrap tabular-nums" />
                  ) : folderSizes?.loading ? (
                    <span className="inline-block h-3 w-10 animate-pulse rounded-md bg-muted align-middle" />
                  ) : (
                    <HoverHint content={t("sizes.notMeasured")}><span>—</span></HoverHint>
                  )}
                  {file.modified_at_human ? (
                    <>
                      {" · "}
                      <span className="whitespace-nowrap">{file.modified_at_human}</span>
                    </>
                  ) : null}
                </p>
                {file.owner || file.mode ? (
                  <p className="flex flex-wrap gap-x-2 font-mono text-xs leading-relaxed text-muted-foreground">
                    {file.owner ? (
                      <span className="whitespace-nowrap">{[file.owner, file.group].filter(Boolean).join(":")}</span>
                    ) : null}
                    {file.mode ? (
                      <HoverHint content={file.mode}><span
                        className={cn("whitespace-nowrap", isWorldWritable(file.mode) && "font-medium text-destructive")}
                      >
                        {symbolicMode(file.mode, file.type) ?? file.mode}
                      </span></HoverHint>
                    ) : null}
                  </p>
                ) : null}
              </div>
            </div>
            {busy ? (
              <Loader2 className="size-4 shrink-0 animate-spin text-muted-foreground" />
            ) : (
              <FileRowActions file={file} appId={appId} canManage={canManage} onAction={onAction} />
            )}
          </li>
        );
      })}
    </ul>
  );
}
