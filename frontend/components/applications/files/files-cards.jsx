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

// `folderSizes` is the table's too, so phones show the same folder sizes.
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
              {/* Selection works on phones too. */}
              <Checkbox
                className="shrink-0"
                checked={selected.includes(file.path)}
                onCheckedChange={() => onToggle?.(file.path)}
                aria-label={t("bulk.selectOne", { name: file.name })}
              />
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
                  <Link
                    href={`/applications/${appId}/files?path=${encodeURIComponent(file.path)}`}
                    prefetch={false}
                    className={cn("block font-medium hover:underline", FILE_NAME)}
                    title={file.name}
                  >
                    {file.name}
                  </Link>
                ) : file.type === "symlink" ? (
                  <span
                    className={cn(
                      "block truncate font-medium",
                      file.link_broken ? "text-destructive" : "text-muted-foreground",
                    )}
                    // Inline would push the name out of a narrow row, so the target is in the native
                    // tooltip.
                    title={file.link_target ?? undefined}
                  >
                    {file.name}
                    {file.link_target ? (
                      <span className="font-mono text-xs text-muted-foreground"> → {file.link_target}</span>
                    ) : null}
                  </span>
                ) : (
                  // w-full beside `block`: a <button> sizes to its content, so `truncate` would not clip.
                  !canManage || !canOpenFile(file.name) ? (
                    <span className={cn("block w-full font-medium", FILE_NAME)} title={file.name}>
                      {file.name}
                    </span>
                  ) : (
                    <button
                      type="button"
                      onClick={() => onAction(isImageFile(file.name) ? "preview" : "edit", file)}
                      className={cn("block w-full text-left font-medium hover:underline", FILE_NAME)}
                      title={file.name}
                    >
                      {file.name}
                    </button>
                  )
                )}
                {/* Two lines: size and age, then owner and mode, each token unbreakable (one
                    `·`-joined line split `-rw-r--r--` when wrapping). */}
                <p className="text-xs leading-relaxed text-muted-foreground">
                  {file.type !== "dir" ? (
                    <span className="whitespace-nowrap tabular-nums">{file.size_human}</span>
                  ) : measured ? (
                    <span className="whitespace-nowrap tabular-nums">{measured.size_human}</span>
                  ) : folderSizes?.loading ? (
                    // A span, not <Skeleton> (a div), inside this <p>.
                    <span className="inline-block h-3 w-10 animate-pulse rounded-md bg-muted align-middle" />
                  ) : (
                    <span title={t("sizes.notMeasured")}>—</span>
                  )}
                  {file.modified_at_human ? (
                    <>
                      {" · "}
                      <span className="whitespace-nowrap">{file.modified_at_human}</span>
                    </>
                  ) : null}
                </p>
                {/* Spaced, not `·`-joined, so a wrapped second item has no stray leading dot. */}
                {file.owner || file.mode ? (
                  <p className="flex flex-wrap gap-x-2 font-mono text-xs leading-relaxed text-muted-foreground">
                    {file.owner ? (
                      <span className="whitespace-nowrap">{[file.owner, file.group].filter(Boolean).join(":")}</span>
                    ) : null}
                    {file.mode ? (
                      <span
                        className={cn("whitespace-nowrap", isWorldWritable(file.mode) && "font-medium text-destructive")}
                        title={file.mode}
                      >
                        {symbolicMode(file.mode, file.type) ?? file.mode}
                      </span>
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
