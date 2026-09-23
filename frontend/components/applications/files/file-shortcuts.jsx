import Link from "next/link";
import { useTranslations } from "next-intl";
import { Image as ImageIcon, Palette, Plug, FileCog } from "lucide-react";
import { cn } from "@/lib/utils";
import { appShortcuts } from "@/lib/files/app-shortcuts";

const ICONS = { uploads: ImageIcon, themes: Palette, plugins: Plug, config: FileCog };

/**
 * "Jump to" chips for the app this is — Uploads, Themes, Plugins,
 * wp-config.php for WordPress.
 *
 * People arrive with a place in mind by its everyday name ("my uploads"),
 * and the path to it is three folders deep behind names they did not choose.
 * Renders nothing for a site type with no confirmed layout.
 *
 * Its own slim row under the breadcrumb, not part of the toolbar: these are
 * places to go, not things to do to this folder.
 */
export function FileShortcuts({ appId, siteType, path, onAction }) {
  const t = useTranslations("applications.files");
  const shortcuts = appShortcuts(siteType);
  if (!shortcuts.length) return null;

  const chip =
    "inline-flex items-center gap-1.5 rounded-full border border-border/60 bg-card px-2.5 py-1 text-xs font-medium shadow-xs transition-colors hover:bg-muted focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring";

  return (
    <nav aria-label={t("shortcuts.label")} className="flex flex-wrap items-center gap-2">
      <span className="text-xs text-muted-foreground">{t("shortcuts.label")}</span>
      {shortcuts.map(({ key, path: target, type }) => {
        const Icon = ICONS[key];
        const label = t(`shortcuts.${key}`);
        if (type === "file") {
          // A file opens where every file opens — in the editor — instead of
          // navigating to a folder view that would only show its parent.
          const name = target.split("/").pop();
          return (
            <button
              key={key}
              type="button"
              className={chip}
              onClick={() => onAction("edit", { name, path: target, type: "file" })}
            >
              <Icon className="size-3.5 text-muted-foreground" aria-hidden />
              <span className="font-mono">{label}</span>
            </button>
          );
        }
        const here = path === target;
        return (
          <Link
            key={key}
            href={`/applications/${appId}/files?path=${encodeURIComponent(target)}`}
            prefetch={false}
            aria-current={here ? "page" : undefined}
            className={cn(chip, here && "border-primary/40 bg-primary/10 text-primary hover:bg-primary/15")}
          >
            <Icon className={cn("size-3.5", here ? "text-primary" : "text-muted-foreground")} aria-hidden />
            {label}
          </Link>
        );
      })}
    </nav>
  );
}
