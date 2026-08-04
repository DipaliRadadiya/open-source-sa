"use client";

import { usePathname, useParams } from "next/navigation";
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

  return (
    <Breadcrumb>
      <BreadcrumbList>
        <BreadcrumbItem className="text-muted-foreground">{panel}</BreadcrumbItem>
        {title && (
          <>
            <BreadcrumbSeparator />
            <BreadcrumbItem>
              {crumb ? (
                <span className="text-muted-foreground">{title}</span>
              ) : (
                <BreadcrumbPage>{title}</BreadcrumbPage>
              )}
            </BreadcrumbItem>
          </>
        )}
        {crumb && (
          <>
            <BreadcrumbSeparator />
            <BreadcrumbItem>
              <BreadcrumbPage className="font-mono">{crumb}</BreadcrumbPage>
            </BreadcrumbItem>
          </>
        )}
      </BreadcrumbList>
    </Breadcrumb>
  );
}
