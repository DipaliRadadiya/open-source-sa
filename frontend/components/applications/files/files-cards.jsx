"use client";

import Link from "next/link";
import { Folder, Link2, Loader2, Unlink } from "lucide-react";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { Checkbox } from "@/components/ui/checkbox";
import { FileRowActions } from "@/components/applications/files/file-row-actions";
import { FileThumb } from "@/components/applications/files/file-thumb";
import { isImageFile } from "@/lib/files/file-icon";
import { isWorldWritable, symbolicMode } from "@/lib/files/describe-mode";

export function FilesCards({ appId, data, canManage, onAction, busyPath, highlightPath, selected = [], onToggle }) {
  const t = useTranslations("applications.files");
  return (
    <ul className="space-y-2">
      {data.map((file) => {
        const busy = busyPath === file.path;
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
                    className="block truncate font-medium hover:underline"
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
                  <button
                    type="button"
                    onClick={() => onAction(isImageFile(file.name) ? "preview" : "edit", file)}
                    className="block w-full truncate text-left font-medium hover:underline"
                  >
                    {file.name}
                  </button>
                )}
                <p className="text-xs leading-relaxed text-muted-foreground">
                  {/* The card is the narrow-screen view of the same row, so it
                      carries the same facts — user and group included, since a
                      file owned by the wrong one is exactly what someone
                      reaches for this screen to check.

                      Wraps rather than truncating, which is the only way that
                      sentence is true. At 390px this line has 202px and needs
                      290px, so `truncate` cut it at "nextcloud:nextc…" — the
                      owner unreadable, and the mode after it never rendered at
                      all. That mode is not decoration: a world-writable file
                      is printed in red right here, and the one screen where
                      you would go looking for it was hiding it. */}
                  {[
                    file.size_human,
                    file.modified_at_human,
                    file.owner ? [file.owner, file.group].filter(Boolean).join(":") : null,
                  ]
                    .filter(Boolean)
                    .join(" · ")}
                  {file.mode ? (
                    <>
                      {file.size_human || file.modified_at_human || file.owner ? " · " : ""}
                      <span
                        className={isWorldWritable(file.mode) ? "font-medium text-destructive" : undefined}
                        title={file.mode}
                      >
                        {symbolicMode(file.mode, file.type) ?? file.mode}
                      </span>
                    </>
                  ) : null}
                </p>
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
