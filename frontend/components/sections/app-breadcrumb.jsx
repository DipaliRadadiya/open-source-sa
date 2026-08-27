"use client";

import { Fragment } from "react";
import Link from "next/link";
import { usePathname, useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { findActiveNavItem, resolveNavItems } from "@/lib/navigation";
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

/**
 * The trail above the page, and the only way back out of a site.
 *
 * The ancestors are real links: application screens used to carry a separate
 * "Back to Applications" button that skipped the site you were standing in and
 * dropped you on the full list. Here the site itself is a step, so you can go
 * back one level or all the way.
 *
 * The two panels order their parts differently, and it is not arbitrary: an
 * application owns its screens (`/applications/13/php` → Applications › Shop ›
 * PHP Settings), while a server screen owns its records (`/databases/shop` →
 * Server › Database › shop). The entity sits where the URL puts it.
 */
export function AppBreadcrumb({ items }) {
  const pathname = usePathname();
  const params = useParams();
  const t = useTranslations("common");
  const { guardNavigation } = useUnsaved();
  const applicationId = params?.application;
  // Set by the application layout (the site name) or by a detail page (a record).
  const { crumb } = usePageCrumb();

  // Inside an application the server-level "Applications" item also matches the
  // path, so match against that panel's items only — otherwise every
  // application screen is labelled "Application".
  const panelItems = resolveNavItems(items, applicationId).filter((item) =>
    applicationId ? item.level === "application" : item.level !== "application",
  );
  const current = findActiveNavItem(panelItems, pathname);
  const title = current?.title;

  const trail = [];
  // A page that owns its whole trail. Account is reached from the user menu
  // rather than the sidebar, so `findActiveNavItem` had nothing to match and it
  // rendered a lone "Server" — no page name, and the wrong parent for a screen
  // about you rather than the machine.
  if (crumb?.root) {
    trail.push({ key: "root", label: crumb.label, mono: crumb.mono });
  } else if (applicationId) {
    const applicationHref = `/applications/${applicationId}`;
    trail.push({ key: "root", label: t("breadcrumbApplications"), href: "/applications" });
    if (crumb) {
      trail.push({ key: "entity", label: crumb.label, href: applicationHref, mono: crumb.mono });
    }
    // On the site's own dashboard the section IS the site — one crumb, not two
    // saying the same thing.
    if (title && current?.href !== applicationHref) {
      trail.push({ key: "section", label: title });
    }
  } else {
    trail.push({
      key: "root",
      label: t("breadcrumbServer"),
      // The root points at the dashboard, so on the dashboard it points at itself.
      href: pathname === "/dashboard" ? undefined : "/dashboard",
    });
    if (title) {
      trail.push({ key: "section", label: title, href: crumb ? current.href : undefined });
    }
    if (crumb) {
      trail.push({ key: "entity", label: crumb.label, mono: crumb.mono });
    }
  }

  // Below `sm` there is no room for a full trail, so only the last two steps
  // survive — the parent you would actually tap, plus where you are.
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
                    // Tied to the crumb BEFORE it, so a folded trail never
                    // opens with a dangling chevron.
                    foldedBelowSm(index - 1) && "hidden sm:block",
                  )}
                />
              )}
              <BreadcrumbItem
                className={cn("min-w-0", foldedBelowSm(index) && "hidden sm:inline-flex")}
              >
                {isLast ? (
                  // Foreground against muted ancestors is enough to say "you are
                  // here"; bolding it too competes with the h1 repeating it.
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
