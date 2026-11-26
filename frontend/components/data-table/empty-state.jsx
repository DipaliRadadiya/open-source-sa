import {
  Ban,
  CircleAlert,
  EyeOff,
  Folder,
  FolderSearch,
  FolderX,
  ListX,
  Rows3,
  ScanSearch,
  SearchX,
  ShieldOff,
  ShieldX,
  TriangleAlert,
} from "lucide-react";
import { cn } from "@/lib/utils";
import { EmptyArt } from "@/components/data-table/empty-art";

const SEARCH_SUBJECT = new Map([
  [SearchX, Rows3],
  [ScanSearch, Rows3],
  [ListX, Rows3],
  [FolderSearch, Folder],
]);
const NO_BADGE = new Set([TriangleAlert, CircleAlert, ShieldX, ShieldOff, Ban, EyeOff, FolderX]);

export function EmptyState({ icon: Icon, subject, badge, title, description, action, compact = false }) {
  const searching = SEARCH_SUBJECT.has(Icon);
  const art = subject ?? SEARCH_SUBJECT.get(Icon) ?? Icon;
  const mark = badge !== undefined ? badge : searching ? "search" : action && !NO_BADGE.has(Icon) ? "add" : null;

  return (
    <div
      className={cn(
        "flex flex-col items-center justify-center rounded-xl text-center",
        compact
          ? "gap-2 bg-muted/40 px-6 py-6"
          : cn(
              "gap-3 rounded-2xl border border-border/70 bg-card px-6 py-12 shadow-e1",
              "in-data-[slot=card]:border-0 in-data-[slot=card]:bg-transparent in-data-[slot=card]:py-8 in-data-[slot=card]:shadow-none",
              "lg:in-[.list-card-lg]:border-0 lg:in-[.list-card-lg]:bg-transparent lg:in-[.list-card-lg]:shadow-none",
              "xl:in-[.list-card-xl]:border-0 xl:in-[.list-card-xl]:bg-transparent xl:in-[.list-card-xl]:shadow-none",
              "min-[1440px]:in-[.list-card-wide]:border-0 min-[1440px]:in-[.list-card-wide]:bg-transparent min-[1440px]:in-[.list-card-wide]:shadow-none",
            ),
      )}
    >
      <EmptyArt icon={art} badge={mark} compact={compact} />
      <div className="space-y-1">
        <p className={cn("font-semibold", compact && "text-sm")}>{title}</p>
        {description ? (
          <p className="max-w-sm text-sm text-pretty text-muted-foreground">{description}</p>
        ) : null}
      </div>
      {action}
    </div>
  );
}
