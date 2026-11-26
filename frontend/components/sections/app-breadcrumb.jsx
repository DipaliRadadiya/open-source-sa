"use client";

import { Fragment } from "react";
import Link from "@/components/ui/app-link";
import { usePathname, useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import { findActiveNavItem, navTitle, resolveNavItems } from "@/lib/navigation";
import { usePageCrumb } from "@/components/sections/page-crumb";
import { useUnsaved } from "@/components/ui/unsaved-guard";
import { HoverHint } from "@/components/ui/hover-hint";
import { useServerIdentity } from "@/hooks/use-server-identity";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@/components/ui/breadcrumb";

const HOSTNAME = "max-w-48 normal-case tracking-normal sm:max-w-64";

export function AppBreadcrumb({ items }) {
  const pathname = usePathname();
  const params = useParams();
  const t = useTranslations("common");
  const { guardNavigation } = useUnsaved();
  const applicationId = params?.application;
  const { crumb } = usePageCrumb();
  const identity = useServerIdentity();

  const panelItems = resolveNavItems(items, applicationId).filter((item) =>
    applicationId ? item.level === "application" : item.level !== "application",
  );
  const current = findActiveNavItem(panelItems, pathname);
  // Some catalog titles are renamed in the frontend; the trail must match the sidebar.
  const title = current ? navTitle(current, t) : undefined;

  const trail = [];
  if (crumb?.root) {
    trail.push({ key: "root", label: crumb.label, mono: crumb.mono });
  } else if (applicationId) {
    const applicationHref = `/applications/${applicationId}`;
    trail.push({ key: "root", label: t("breadcrumbApplications"), href: "/applications" });
    if (crumb) {
      trail.push({ key: "entity", label: crumb.label, href: applicationHref, mono: crumb.mono });
    }
    if (title && current?.href !== applicationHref) {
      trail.push({ key: "section", label: title });
    }
  } else {
    trail.push({
      key: "root",
      label: identity?.hostname ?? t("breadcrumbServer"),
      hostname: Boolean(identity?.hostname),
      href: pathname === "/dashboard" ? undefined : "/dashboard",
    });
    if (title) {
      trail.push({ key: "section", label: title, href: crumb ? current.href : undefined });
    }
    if (crumb) {
      trail.push({ key: "entity", label: crumb.label, mono: crumb.mono });
    }
  }

  const foldedBelowSm = (index) => index < trail.length - 2;

  return (
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
                    foldedBelowSm(index - 1) && "hidden sm:block",
                  )}
                >/</BreadcrumbSeparator>
              )}
              <BreadcrumbItem
                className={cn("min-w-0", foldedBelowSm(index) && "hidden sm:inline-flex")}
              >
                {isLast ? (
                  <BreadcrumbPage className={cn("truncate font-medium", item.mono && "font-mono", item.hostname && HOSTNAME)}>
                    {item.label}
                  </BreadcrumbPage>
                ) : item.href ? (
                  <HoverHint content={item.hostname ? item.label : null}><BreadcrumbLink asChild>
                    <Link
                      href={item.href}
                      onClick={(event) => {
                        if (guardNavigation(item.href)) event.preventDefault();
                      }}
                      className={cn(
                        "truncate rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2",
                        item.mono && "font-mono",
                        item.hostname && HOSTNAME,
                      )}
                    >
                      {item.label}
                    </Link>
                  </BreadcrumbLink></HoverHint>
                ) : (
                  <HoverHint content={item.hostname ? item.label : null}>
                    <span className={cn("truncate", item.mono && "font-mono", item.hostname && HOSTNAME)}>{item.label}</span>
                  </HoverHint>
                )}
              </BreadcrumbItem>
            </Fragment>
          );
        })}
      </BreadcrumbList>
    </Breadcrumb>
  );
}
