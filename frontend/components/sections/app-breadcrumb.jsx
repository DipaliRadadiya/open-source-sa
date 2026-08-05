"use client";

import { usePathname, useParams } from "next/navigation";
import { cn } from "@/lib/utils";
import { findActiveNavItem, resolveNavItems } from "@/lib/navigation";
import { usePageCrumb } from "@/components/sections/page-crumb";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@/components/ui/breadcrumb";

export function AppBreadcrumb({ items }) {
  const pathname = usePathname();
  const params = useParams();
  const panel = params?.application ? "Application" : "Server";

  // Inside an application the server-level "Applications" item also matches the
  // path, so match against that panel's items only — otherwise every
  // application screen is labelled "Application".
  const panelItems = resolveNavItems(items, params?.application).filter((item) =>
    params?.application ? item.level === "application" : item.level !== "application",
  );
  const current = findActiveNavItem(panelItems, pathname);
  const title = current?.title;
  // Set by detail pages; null everywhere else.
  const { crumb } = usePageCrumb();

  // Which level is the deepest one present — the only crumb kept on a phone,
  // where there is no room to show ancestors beside the header controls.
  const last = crumb ? "crumb" : title ? "title" : "panel";

  return (
    // Never wrap: a wrapped trail doubles the header height on mobile. Ancestors
    // are hidden below `sm` (see per-item classes); the full trail returns at sm+.
    <Breadcrumb className="min-w-0">
      <BreadcrumbList className="flex-nowrap">
        <BreadcrumbItem
          className={cn(
            "text-muted-foreground",
            last !== "panel" && "hidden sm:inline-flex",
          )}
        >
          {panel}
        </BreadcrumbItem>
        {title && (
          <>
            <BreadcrumbSeparator className="hidden sm:block" />
            <BreadcrumbItem
              className={cn(
                "min-w-0",
                last !== "title" && "hidden sm:inline-flex",
              )}
            >
              {crumb ? (
                <span className="truncate text-muted-foreground">{title}</span>
              ) : (
                <BreadcrumbPage className="truncate">{title}</BreadcrumbPage>
              )}
            </BreadcrumbItem>
          </>
        )}
        {crumb && (
          <>
            <BreadcrumbSeparator className="hidden sm:block" />
            <BreadcrumbItem className="min-w-0">
              <BreadcrumbPage className="truncate font-mono">{crumb}</BreadcrumbPage>
            </BreadcrumbItem>
          </>
        )}
      </BreadcrumbList>
    </Breadcrumb>
  );
}
