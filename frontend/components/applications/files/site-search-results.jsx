"use client";

import { useEffect, useState } from "react";
import Link from "next/link";
import { useTranslations } from "next-intl";
import { Folder, Link2, Loader2, SearchX } from "lucide-react";
import { cn } from "@/lib/utils";
import { searchFiles } from "@/lib/api/files";
import { searchResponseSchema } from "@/lib/schemas/file";
import { apiMessage } from "@/lib/api/error-message";
import { dirname } from "@/lib/files/path-helpers";
import { EmptyState } from "@/components/data-table/empty-state";
import { fileIconFor, isImageFile } from "@/lib/files/file-icon";
import { Tooltip, TooltipContent, TooltipTrigger } from "@/components/ui/tooltip";

/**
 * Results for "Search entire site" — recursive, so unlike the folder table
 * each row can be anywhere on the site. Clicking a **file** opens it, same
 * as clicking one in the regular listing — a click that just navigated to
 * the containing folder read as "nothing happened" whenever that folder was
 * already the one you searched from. Clicking a **folder** navigates into
 * it, since that IS how folders open. The folder each result lives in is
 * still shown, as its own small link, for "take me there instead."
 */
export function SiteSearchResults({ appId, query, onAction }) {
  const t = useTranslations("applications.files");
  const [remote, setRemote] = useState({ status: "loading", files: [], message: null });

  useEffect(() => {
    // Mounted fresh per search (see files-panel.jsx — editing the query while
    // results are showing unmounts this), so `remote`'s initial "loading"
    // state is already correct without resetting it here.
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
    return <EmptyState icon={SearchX} title={t("siteSearch.failed")} description={remote.message} />;
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

        return (
          <li key={file.path} className="flex items-center justify-between gap-3 rounded-xl border bg-card p-3">
            <div className="flex min-w-0 items-center gap-2.5">
              <Icon className={cn("size-4 shrink-0", symlink ? "text-muted-foreground" : className)} />
              <div className="min-w-0">
                {isDir ? (
                  <Link href={folderHref} className="block truncate font-medium hover:underline">
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
                  <button
                    type="button"
                    onClick={() => onAction(isImageFile(file.name) ? "preview" : "edit", file)}
                    className="block truncate text-left font-medium hover:underline"
                  >
                    {file.name}
                  </button>
                )}
                <p className="truncate text-xs text-muted-foreground">
                  {isDir ? (
                    folderLabel
                  ) : (
                    <Link href={folderHref} className="hover:text-foreground hover:underline">
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
