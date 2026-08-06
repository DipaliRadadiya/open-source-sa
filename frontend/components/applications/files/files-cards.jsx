"use client";

import Link from "next/link";
import { Folder, Link2, Loader2 } from "lucide-react";
import { cn } from "@/lib/utils";
import { FileRowActions } from "@/components/applications/files/file-row-actions";
import { FileThumb } from "@/components/applications/files/file-thumb";
import { isImageFile } from "@/lib/files/file-icon";
import { isWorldWritable } from "@/lib/files/describe-mode";

export function FilesCards({ appId, data, canManage, onAction, busyPath, highlightPath }) {
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
              {file.type === "dir" ? (
                <Folder className="size-4 shrink-0 text-primary" />
              ) : file.type === "symlink" ? (
                <Link2 className="size-4 shrink-0 text-muted-foreground" />
              ) : (
                <FileThumb file={file} appId={appId} className="size-5" />
              )}
              <div className="min-w-0">
                {file.type === "dir" ? (
                  <Link
                    href={`/applications/${appId}/files?path=${encodeURIComponent(file.path)}`}
                    className="block truncate font-medium hover:underline"
                  >
                    {file.name}
                  </Link>
                ) : file.type === "symlink" ? (
                  <span className="block truncate font-medium text-muted-foreground">{file.name}</span>
                ) : (
                  <button
                    type="button"
                    onClick={() => onAction(isImageFile(file.name) ? "preview" : "edit", file)}
                    className="block truncate text-left font-medium hover:underline"
                  >
                    {file.name}
                  </button>
                )}
                <p className="truncate text-xs text-muted-foreground">
                  {[file.size_human, file.modified_at_human].filter(Boolean).join(" · ")}
                  {file.mode ? (
                    <>
                      {file.size_human || file.modified_at_human ? " · " : ""}
                      <span className={isWorldWritable(file.mode) ? "font-medium text-destructive" : undefined}>
                        {file.mode}
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
