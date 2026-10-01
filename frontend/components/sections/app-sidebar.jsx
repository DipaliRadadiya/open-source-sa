"use client";

import Link from "@/components/ui/app-link";
import { useLinkStatus } from "next/link";
import { usePathname, useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { ArrowLeft, Loader2 } from "lucide-react";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { cn } from "@/lib/utils";
import {
  groupBySubLevel,
  navTitle,
  isNavBuilt,
  findActiveNavItem,
  resolveNavItems,
  NAV_ITEM_CLASS,
} from "@/lib/navigation";
import { useApplicationNav } from "@/components/sections/application-nav";
import { Logo } from "@/components/logo";
import { ApplicationStatusDot } from "@/components/applications/application-status-badge";
import { VisitSiteLink } from "@/components/applications/visit-site-link";
import { useUnsaved } from "@/components/ui/unsaved-guard";
import { SidebarLevelTransition } from "@/components/sections/sidebar-level-transition";
import { NavIcon } from "@/components/nav-icon";
import {
  Sidebar,
  SidebarContent,
  SidebarHeader,
  SidebarGroup,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuItem,
  SidebarMenuButton,
  SidebarRail,
  useSidebar,
} from "@/components/ui/sidebar"

/**
 * Closes the mobile sidebar sheet after a nav click, and holds the click when the
 * page has unsaved edits. A sidebar click is a client-side route change that
 * `beforeunload` never sees; every nav item funnels through here.
 */
function MobileNavLink({ item, built, active, children, className }) {
  const { isMobile, setOpenMobile } = useSidebar()
  const t = useTranslations("common")
  const { guardNavigation } = useUnsaved()

  if (!built) {
    return (
      <SidebarMenuButton
        asChild
        tooltip={`${navTitle(item, t)} · ${t("soon")}`}
        className={cn(NAV_ITEM_CLASS, "cursor-default text-muted-foreground/55 hover:bg-transparent hover:text-muted-foreground/55")}
      >
        <span aria-disabled="true">
          {children}
          <span className="ml-auto rounded-sm bg-muted px-1.5 py-0.5 text-xs font-medium uppercase tracking-wide text-muted-foreground/70 group-data-[collapsible=icon]:hidden">
            {t("soon")}
          </span>
        </span>
      </SidebarMenuButton>
    )
  }

  const handleClick = (event) => {
    // `asChild` merges this onto the anchor, so preventing the click holds the route
    // while the provider asks about unsaved work.
    if (
      !active &&
      item.href &&
      guardNavigation(item.href, () => isMobile && setOpenMobile(false))
    ) {
      event.preventDefault()
      return
    }
    if (isMobile) setOpenMobile(false)
  }

  return (
    <SidebarMenuButton
      asChild
      isActive={active}
      tooltip={navTitle(item, t)}
      className={cn(NAV_ITEM_CLASS, className)}
      onClick={handleClick}
    >
      {children}
    </SidebarMenuButton>
  )
}

// Shows the wait at the click point as well as in the top bar.
function PendingNavIcon({ name }) {
  const { pending } = useLinkStatus();

  return pending ? <Loader2 className="size-4 animate-spin" /> : <NavIcon name={name} />;
}

export function AppSidebar({ items }) {
  const pathname = usePathname();
  const params = useParams();
  const t = useTranslations("common");
  const { state, isMobile } = useSidebar();
  const { guardNavigation } = useUnsaved();

  const applicationId = params?.application;
  const iconOnly = state === "collapsed" && !isMobile;

  // Inside an application, prefer the catalog its layout fetched: only that one is
  // filtered by site type. Until it arrives, the shared catalog renders the same
  // items without that filter.
  const { items: applicationItems, resolved, application, gitProvider } = useApplicationNav();
  // Once the layout answers "no menu", the site does not exist: fall back to the
  // SERVER panel rather than a site menu whose links all 404.
  const insideApplication = Boolean(applicationId) && (applicationItems !== null || !resolved);
  const currentPanel = insideApplication ? "application" : "server";
  const source = insideApplication ? (applicationItems ?? items) : items;

  // Every application screen in the catalog is shown; routes not yet built render
  // as non-clickable "Soon" rows rather than being hidden or 404ing.
  const visible = resolveNavItems(source, applicationId)
    .filter((item) => item?.permissions?.view)
    .filter((item) => item.level === currentPanel);

  const groups = groupBySubLevel(visible);

  // Longest match wins: the application Dashboard's href (`/applications/{id}`)
  // prefixes every sub-page, so pick the single deepest match.
  const activeItem = findActiveNavItem(visible, pathname);

  return (
    <Sidebar collapsible="icon" label={t("serverNavigation")}>
      <SidebarHeader className="h-16 justify-center border-b px-3">
        <Link
          href="/dashboard"
          className="flex items-center"
          onClick={(event) => {
            if (pathname !== "/dashboard" && guardNavigation("/dashboard")) event.preventDefault();
          }}
        >
          {iconOnly ? (
            <Logo collapsed className="size-8" />
          ) : (
            <Logo className="h-8 w-auto" />
          )}
        </Link>
      </SidebarHeader>
      {application ? (
        <SidebarGroup className="border-b px-2 pt-2 pb-4">
          <SidebarMenu className="gap-1">
            <SidebarMenuItem>
              <MobileNavLink
                item={{ href: "/applications", title: t("applicationContext.back") }}
                built
                active={false}
              >
                <Link href="/applications" prefetch={false}>
                  <ArrowLeft />
                  <span>{t("applicationContext.back")}</span>
                </Link>
              </MobileNavLink>
            </SidebarMenuItem>
            <SidebarMenuItem className="relative">
              <MobileNavLink
                item={{ href: `/applications/${application.id}`, title: application.name }}
                built
                active={false}
                /*
                 * Tinted and bordered so the card reads as the subject of the nav below, not as
                 * another nav item.
                 */
                className="h-auto min-h-20 items-start rounded-xl border border-primary/25 bg-primary/5 p-3 hover:bg-primary/10 group-data-[collapsible=icon]:min-h-8! group-data-[collapsible=icon]:p-2!"
              >
                <Link
                  href={`/applications/${application.id}`}
                  prefetch={false}
                  className="min-w-0 flex-col items-stretch gap-0"
                >
                  <span className="flex w-full min-w-0 items-center gap-2.5">
                    {/* On its own tile so the brand mark has a surface against the tint. */}
                    <span className="flex size-9 shrink-0 items-center justify-center rounded-lg border bg-background shadow-xs group-data-[collapsible=icon]:size-5! group-data-[collapsible=icon]:border-0! group-data-[collapsible=icon]:bg-transparent! group-data-[collapsible=icon]:shadow-none!">
                      <SiteTypeLogo name={application.site_type} provider={gitProvider} size="h-5 w-5" />
                    </span>
                    {/* Hidden explicitly when the rail collapses to icons: the sidebar only hides a
                        button's LAST span (the domain line), and the rest would overflow. */}
                    <span className="min-w-0 flex-1 leading-tight group-data-[collapsible=icon]:hidden">
                      <span className="block truncate text-sm font-semibold" title={application.name}>
                        {application.name}
                      </span>
                      <ApplicationStatusDot application={application} className="mt-1" />
                    </span>
                  </span>
                  {/* Its own full-width line, in a box: the domain is often the longest string and
                      would truncate mid-host beside the name. */}
                  <span className="mt-2.5 block w-full truncate rounded-md bg-background/80 px-2 py-1 font-mono text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
                    {application.domain}
                  </span>
                </Link>
              </MobileNavLink>
              {/* Sibling, not child: the card is already a link and anchors cannot nest. Only
                  while the site is served (a provisioning site would show a connection error).
                  Hidden when the rail is collapsed to icons. */}
              {application.status === "active" && application.url ? (
                <VisitSiteLink
                  href={application.url}
                  label={t("applicationContext.visit", { domain: application.domain })}
                  className="absolute top-2 right-2 group-data-[collapsible=icon]:hidden"
                />
              ) : null}
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarGroup>
      ) : null}
      <SidebarContent className="gap-0 py-2">
        {/* Keyed on level AND application id: switching sites is also a level change,
            though `currentPanel` stays "application". */}
        <SidebarLevelTransition level={insideApplication ? `application:${applicationId}` : "server"}>
        {groups.map((group) => (
          <SidebarGroup key={group.key} className="py-1">
            {group.key && (
              <SidebarGroupLabel className="text-xs font-medium uppercase tracking-wider text-muted-foreground">
                {group.title}
              </SidebarGroupLabel>
            )}
            <SidebarMenu className="gap-1.5">
              {group.items.map((item) => {
                const built = isNavBuilt(currentPanel, item.url);
                const active = item === activeItem;

                if (!built) {
                  return (
                    <SidebarMenuItem key={`${item.name}-${item.href}`}>
                      <MobileNavLink item={item} built={false} active={active}>
                        <NavIcon name={item.icon} />
                        <span>{navTitle(item, t)}</span>
                      </MobileNavLink>
                    </SidebarMenuItem>
                  )
                }

                return (
                  <SidebarMenuItem key={`${item.name}-${item.href}`}>
                    <MobileNavLink item={item} built active={active}>
                      {/* No prefetch: every route is dynamic and cookie-gated, so each prefetch is a full
                          server render against the API's rate limit, and the whole menu is always on
                          screen. The item spins until the page arrives instead. */}
                      <Link href={item.href} prefetch={false}>
                        <PendingNavIcon name={item.icon} />
                        <span>{navTitle(item, t)}</span>
                      </Link>
                    </MobileNavLink>
                  </SidebarMenuItem>
                );
              })}
            </SidebarMenu>
          </SidebarGroup>
        ))}
        </SidebarLevelTransition>
      </SidebarContent>
      <SidebarRail />
    </Sidebar>
  );
}
