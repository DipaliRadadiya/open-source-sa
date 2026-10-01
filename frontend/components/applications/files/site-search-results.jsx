import { useEffect, useState } from "react";
import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Folder, Link2, Loader2, SearchX } from "lucide-react";
import { cn } from "@/lib/utils";
import { searchFiles } from "@/lib/api/files";
import { searchResponseSchema } from "@/lib/schemas/file";
import { apiMessage } from "@/lib/api/error-message";
import { dirname } from "@/lib/files/path-helpers";
import { EmptyState } from "@/components/data-table/empty-state";
import { fileIconFor, isImageFile } from "@/lib/files/file-icon";
import { canOpenFile } from "@/lib/files/openable";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

// The name's ::after spans the <li>; its `truncate` does not clip it, as the containing block is outside.
const STRETCH = "after:absolute after:inset-0 after:rounded-xl";

// Row target is an overlay on the name, not a wrapping link: the row also holds the folder link (no <a> in <a>).
export function SiteSearchResults({ appId, query, onAction, canManage = true }) {
  const t = useTranslations("applications.files");
  const [remote, setRemote] = useState({ status: "loading", files: [], message: null });

  useEffect(() => {
    // Mounted fresh per search (see files-panel.jsx), so `remote` starts in the
    // correct "loading" state.
    let active = true;
    const controller = new AbortController();
    searchFiles(appId, query, { signal: controller.signal })
      .then(({ data }) => {
        if (!active) return;
        const parsed = searchResponseSchema.safeParse(data);
        setRemote({ status: "done", files: parsed.success ? parsed.data.files : [], message: null });
      })
      .catch((error) => {
        if (!active || error.code === "ERR_CANCELED") return;
        setRemote({ status: "error", files: [], message: apiMessage(error, t("siteSearch.failed")) });
      });
    return () => {
      active = false;
      controller.abort();
    };
    // `t` is excluded: it only words a failure, and re-running would abort the
    // in-flight request via the cleanup above.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [appId, query]);

  const status = remote.status;
  const files = remote.files;

  if (status === "loading") {
    return (
      <div className="flex h-32 items-center justify-center">
        <Loader2 className="size-5 animate-spin text-muted-foreground" />
      </div>
    );
  }

  if (status === "error") {
    // No server reason means the fallback, which is already the title.
    return (
      <EmptyState
        icon={SearchX}
        title={t("siteSearch.failed")}
        description={remote.message === t("siteSearch.failed") ? null : remote.message}
      />
    );
  }

  if (files.length === 0) {
    return (
      <EmptyState
        icon={SearchX}
        title={t("empty.filteredTitle")}
        description={t("siteSearch.noResults", { query })}
      />
    );
  }

  return (
    <ul className="space-y-2">
      {files.map((file) => {
        const symlink = file.type === "symlink";
        const isDir = file.type === "dir";
        const { icon: FileIcon, className } = isDir ? { icon: Folder, className: "text-primary" } : fileIconFor(file.name);
        const Icon = symlink ? Link2 : FileIcon;
        const folder = dirname(file.path);
        const folderHref = `/applications/${appId}/files?path=${encodeURIComponent(folder)}`;
        const folderLabel = folder ? t("siteSearch.inFolder", { folder }) : t("root");
        // Into the folder itself; `folderHref` is its PARENT.
        const openDirHref = `/applications/${appId}/files?path=${encodeURIComponent(file.path)}`;
        const openable = canManage && !symlink && !isDir && canOpenFile(file.name);
        // Only rows that lead somewhere get the row-wide hover affordance.
        const interactive = isDir || openable;

        return (
          <li
            key={file.path}
            className={cn(
              "relative flex items-center justify-between gap-3 rounded-xl border bg-card p-3",
              interactive &&
                "transition-colors hover:bg-accent/40 has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-ring",
            )}
          >
            <div className="flex min-w-0 items-center gap-2.5">
              <Icon className={cn("size-4 shrink-0", symlink ? "text-muted-foreground" : className)} />
              <div className="min-w-0">
                {isDir ? (
                  <Link href={openDirHref} className={cn("block truncate font-medium hover:underline", STRETCH)}>
                    {file.name}
                  </Link>
                ) : symlink ? (
                  <Tooltip>
                    <TooltipTrigger asChild>
                      <span tabIndex={0} className="block truncate font-medium text-muted-foreground">
                        {file.name}
                      </span>
                    </TooltipTrigger>
                    <TooltipContent className="max-w-60">{t("symlinkHint")}</TooltipContent>
                  </Tooltip>
                ) : (
                  // Nothing to open: see the note in files-table.
                  !openable ? (
                    <span className="block w-full truncate font-medium" title={file.name}>
                      {file.name}
                    </span>
                  ) : (
                    <button
                      type="button"
                      onClick={() => onAction(isImageFile(file.name) ? "preview" : "edit", file)}
                      className={cn("block w-full cursor-pointer truncate text-left font-medium hover:underline", STRETCH)}
                    >
                      {file.name}
                    </button>
                  )
                )}
                <p className="truncate text-xs text-muted-foreground">
                  {isDir ? (
                    folderLabel
                  ) : (
                    // Above the row overlay so the folder link stays clickable.
                    <Link href={folderHref} className="relative z-10 hover:text-foreground hover:underline">
                      {folderLabel}
                    </Link>
                  )}
                </p>
              </div>
            </div>
          </li>
        );
      })}
    </ul>
  );
}
