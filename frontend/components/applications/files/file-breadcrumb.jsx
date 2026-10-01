import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Folder, ChevronRight } from "lucide-react";
import { CopyButton } from "@/components/ui/copy-button";

function href(appId, segments, upTo) {
  const path = segments.slice(0, upTo).join("/");
  return path ? `/applications/${appId}/files?path=${encodeURIComponent(path)}` : `/applications/${appId}/files`;
}

// In-page path navigator; collapses the middle once a path is deep enough to wrap.
export function FileBreadcrumb({ appId, path }) {
  const t = useTranslations("applications.files");
  const segments = path ? path.split("/").filter(Boolean) : [];
  const collapsed = segments.length > 4;
  const visible = collapsed
    ? [null, ...segments.slice(-2)] // null marks the "…" placeholder
    : segments;
  const hiddenCount = collapsed ? segments.length - 2 : 0;

  return (
    <nav aria-label={t("breadcrumbLabel")} className="flex min-w-0 items-center gap-1 overflow-x-auto text-sm">
      <Link
        href={href(appId, segments, 0)}
        prefetch={false}
        className="flex shrink-0 items-center gap-1.5 rounded px-1 py-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
      >
        <Folder className="size-3.5" />
        {t("root")}
      </Link>
      {visible.map((seg, i) => {
        const isLast = i === visible.length - 1;
        // Index into the real segments for the href, offset by the segments skipped when
        // collapsed.
        const realIndex = collapsed ? segments.length - visible.length + i + 1 : i + 1;
        return (
          <span key={i} className="flex shrink-0 items-center gap-1">
            <ChevronRight className="size-3.5 text-muted-foreground/60" />
            {seg === null ? (
              <span
                className="px-1 py-0.5 text-muted-foreground"
                title={segments.slice(0, hiddenCount).join("/")}
              >
                …
              </span>
            ) : isLast ? (
              <span className="max-w-40 truncate px-1 py-0.5 font-medium sm:max-w-64">{seg}</span>
            ) : (
              <Link
                href={href(appId, segments, realIndex)}
                prefetch={false}
                className="max-w-40 truncate rounded px-1 py-0.5 text-muted-foreground hover:text-foreground focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring sm:max-w-64"
              >
                {seg}
              </Link>
            )}
          </span>
        );
      })}
      {/* Nothing to copy at the root: every relative path is relative to it. */}
      {path ? <CopyButton value={path} label={t("actions.copyPath")} className="ml-0.5" /> : null}
    </nav>
  );
}
