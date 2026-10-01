"use client";

import { Fragment } from "react";
import Link from "@/components/ui/app-link";
import { usePathname, useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { findActiveNavItem, navTitle, resolveNavItems } from "@/lib/navigation";
import { usePageCrumb } from "@/components/sections/page-crumb";
import { useUnsaved } from "@/components/ui/unsaved-guard";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@/components/ui/breadcrumb";

// Ordering follows the URL: `/applications/13/php` → Applications › Shop › PHP Settings.
export function AppBreadcrumb({ items }) {
  const pathname = usePathname();
  const params = useParams();
  const t = useTranslations("common");
  const { guardNavigation } = useUnsaved();
  const applicationId = params?.application;
  // Set by the application layout (the site name) or by a detail page (a record).
  const { crumb } = usePageCrumb();

  // Inside an application the server-level "Applications" item also matches the
  // path, so match against that panel's items only.
  const panelItems = resolveNavItems(items, applicationId).filter((item) =>
    applicationId ? item.level === "application" : item.level !== "application",
  );
  const current = findActiveNavItem(panelItems, pathname);
  // `navTitle`, not `current.title`: the frontend renames some catalog titles
  // (e.g. "8G Firewall"), and the trail must match the sidebar.
  const title = current ? navTitle(current, t) : undefined;

  const trail = [];
  // A page that owns its whole trail (e.g. Account, reached from the user menu, has
  // no sidebar item to match).
  if (crumb?.root) {
    trail.push({ key: "root", label: crumb.label, mono: crumb.mono });
  } else if (applicationId) {
    const applicationHref = `/applications/${applicationId}`;
    trail.push({ key: "root", label: t("breadcrumbApplications"), href: "/applications" });
    if (crumb) {
      trail.push({ key: "entity", label: crumb.label, href: applicationHref, mono: crumb.mono });
    }
    // On the site's own dashboard the section is the site: one crumb, not two.
    if (title && current?.href !== applicationHref) {
      trail.push({ key: "section", label: title });
    }
  } else {
    trail.push({
      key: "root",
      label: t("breadcrumbServer"),
      // The root points at the dashboard, so on the dashboard it is not a link.
      href: pathname === "/dashboard" ? undefined : "/dashboard",
    });
    if (title) {
      trail.push({ key: "section", label: title, href: crumb ? current.href : undefined });
    }
    if (crumb) {
      trail.push({ key: "entity", label: crumb.label, mono: crumb.mono });
    }
  }

  // Below `sm` only the last two steps show: the parent to tap, and the current page.
  const foldedBelowSm = (index) => index < trail.length - 2;

  return (
    // Never wrap: a wrapped trail doubles the height above every page.
    <Breadcrumb className="min-w-0">
      <BreadcrumbList className="flex-nowrap gap-1.5 sm:gap-2">
        {trail.map((item, index) => {
          const isLast = index === trail.length - 1;
          return (
            <Fragment key={item.key}>
              {index > 0 && (
                <BreadcrumbSeparator
                  className={cn(
                    "text-muted-foreground/50",
                    // Tied to the crumb BEFORE it, so a folded trail never opens with a dangling
                    // chevron.
                    foldedBelowSm(index - 1) && "hidden sm:block",
                  )}
                />
              )}
              <BreadcrumbItem
                className={cn("min-w-0", foldedBelowSm(index) && "hidden sm:inline-flex")}
              >
                {isLast ? (
                  // Foreground against muted ancestors marks "you are here"; bold would compete with
                  // the h1.
                  <BreadcrumbPage className={cn("truncate", item.mono && "font-mono")}>
                    {item.label}
                  </BreadcrumbPage>
                ) : item.href ? (
                  <BreadcrumbLink asChild>
                    <Link
                      href={item.href}
                      onClick={(event) => {
                        if (guardNavigation(item.href)) event.preventDefault();
                      }}
                      className={cn(
                        "truncate rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                        item.mono && "font-mono",
                      )}
                    >
                      {item.label}
                    </Link>
                  </BreadcrumbLink>
                ) : (
                  <span className={cn("truncate", item.mono && "font-mono")}>{item.label}</span>
                )}
              </BreadcrumbItem>
            </Fragment>
          );
        })}
      </BreadcrumbList>
    </Breadcrumb>
  );
}
