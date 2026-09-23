import Link from "next/link";
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

/*
 * `folderSizes` and `sizingPaths` are the same two pieces of state the desktop
 * table's SizeCell reads. Without them the ⋯ → "Folder size" action on a phone
 * ran, measured, stored the answer — and had nowhere to show it, so the menu
 * closed and nothing ever happened. The action was only ever wired into the
 * table.
 */
export function FilesCards({
  appId,
  data,
  canManage,
  onAction,
  busyPath,
  highlightPath,
  selected = [],
  onToggle,
  folderSizes = {},
  sizingPaths = [],
}) {
  const t = useTranslations("applications.files");
  // "Measuring…" already exists one namespace up, shared with the dashboard.
  // Adding a files-scoped copy would be a second string for one sentence.
  const tSize = useTranslations("applications.size");
  return (
    <ul className="space-y-2">
      {data.map((file) => {
        const busy = busyPath === file.path;
        const measuring = sizingPaths.includes(file.path);
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
              {/* Same selection on a phone as on a desktop. Bulk work is exactly
                  what you reach for on the small screen, where doing it one row
                  at a time is most painful. */}
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
                <FileThumb file={file} appId={appId} className="size-5" />
              )}
              <div className="min-w-0">
                {file.type === "dir" ? (
                  <Link
                    href={`/applications/${appId}/files?path=${encodeURIComponent(file.path)}`}
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
                    // Inline on the card would push the name out of a narrow
                    // row, so the target lives in the native tooltip here.
                    title={file.link_target ?? undefined}
                  >
                    {file.name}
                    {file.link_target ? (
                      <span className="font-mono text-xs text-muted-foreground/70"> → {file.link_target}</span>
                    ) : null}
                  </span>
                ) : (
                  // w-full is not redundant next to `block`. A <button> is a form
                  // control and sizes to its own content even when block-level,
                  // so `truncate` was clipping at the button's width — which was
                  // already wider than the column — and the name spilled across
                  // the row's action icons instead of ellipsing.
                  // Nothing to open — see the note in files-table.
                  !canOpenFile(file.name) ? (
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
                {/* Two lines, not one run. The card is the phone view of the
                    same row, so it keeps every fact the table has — owner and
                    mode included, since a file owned by the wrong account is
                    exactly what people come here to check. But as one
                    `·`-joined line it wrapped wherever it ran out, which split
                    `-rw-r--r--` itself in half. Now: what it is (size, age),
                    then who may touch it, each token unbreakable. */}
                <p className="text-xs leading-relaxed text-muted-foreground">
                  {measuring ? (
                    tSize("measuring")
                  ) : file.type === "dir" && !folderSizes[file.path] ? (
                    <button
                      type="button"
                      onClick={() => onAction("size", file)}
                      className="underline decoration-dotted underline-offset-2 hover:text-foreground"
                    >
                      {t("size.calculate")}
                    </button>
                  ) : (
                    <span className="whitespace-nowrap tabular-nums">
                      {file.type === "dir" ? folderSizes[file.path] : file.size_human}
                    </span>
                  )}
                  {file.modified_at_human ? (
                    <>
                      {" · "}
                      <span className="whitespace-nowrap">{file.modified_at_human}</span>
                    </>
                  ) : null}
                </p>
                {/* Spaced, not `·`-joined: when the two do not fit on one
                    line the second wraps whole, and a dot left at the start
                    of the new line read as a stray mark. */}
                {file.owner || file.mode ? (
                  <p className="flex flex-wrap gap-x-2 font-mono text-xs leading-relaxed text-muted-foreground/80">
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
