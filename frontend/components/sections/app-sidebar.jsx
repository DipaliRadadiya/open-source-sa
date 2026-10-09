"use client";

import Link from "@/components/ui/app-link";
import { useState } from "react";
import { useLinkStatus } from "next/link";
import { usePathname, useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { ArrowLeft, ChevronDown, Loader2 } from "lucide-react";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { cn } from "@/lib/utils";
import {
  groupNavItems,
  COLLAPSED_GROUPS,
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
import { SidebarServerCard } from "@/components/sections/sidebar-server-card";
import { can } from "@/lib/permissions/can";
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

// Every nav item funnels through here: a sidebar click is a client-side route change
// that `beforeunload` never sees, so unsaved edits are held here.
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
          <span className="ml-auto rounded-sm bg-muted px-1.5 py-0.5 text-xs font-medium text-muted-foreground/70 group-data-[collapsible=icon]:hidden">
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

  // Inside an application, prefer its layout's catalog (filtered by site type);
  // until it arrives the shared catalog renders the same items unfiltered.
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

  const groups = groupNavItems(visible);

  // Longest match wins: the application Dashboard's href (`/applications/{id}`)
  // prefixes every sub-page, so pick the single deepest match.
  const activeItem = findActiveNavItem(visible, pathname);

  // Only what the person toggled; everything else follows the default.
  const [toggled, setToggled] = useState({});
  const groupTitle = (group) =>
    group.named ? t(`navGroups.${group.key === "protection" ? "security" : group.key}`) : group.title;

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
      {/* Which machine this is; only someone who may read the dashboard is asked. */}
      {!insideApplication && can(items, "dashboard", "view") ? (
        <SidebarGroup className="px-2 pt-3 pb-0 group-data-[collapsible=icon]:hidden">
          <SidebarServerCard />
        </SidebarGroup>
      ) : null}
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
                /* As in the redesign: a white card washed with the brand colour from one corner. */
                className="relative h-auto min-h-20 items-start overflow-hidden rounded-xl bg-card bg-linear-135 from-primary/10 to-transparent to-70% p-3 shadow-e1 ring-1 ring-border/70 hover:bg-card hover:from-primary/15 active:bg-card data-[active=true]:bg-card group-data-[collapsible=icon]:min-h-8! group-data-[collapsible=icon]:p-2!"
              >
                <Link
                  href={`/applications/${application.id}`}
                  prefetch={false}
                  className="min-w-0 flex-col items-stretch gap-0"
                >
                  <span className="relative flex w-full min-w-0 items-center gap-2.5">
                    {/* On its own tile so the brand mark has a surface against the tint. */}
                    <span className="flex size-10 shrink-0 items-center justify-center rounded-lg bg-card shadow-e1 ring-1 ring-border group-data-[collapsible=icon]:size-5! group-data-[collapsible=icon]:ring-0! group-data-[collapsible=icon]:border-0! group-data-[collapsible=icon]:shadow-none!">
                      <SiteTypeLogo name={application.site_type} provider={gitProvider} size="h-5 w-5" />
                    </span>
                    {/* Hidden explicitly when the rail collapses to icons: the sidebar only hides a
                        button's LAST span (the domain line), and the rest would overflow. */}
                    <span className="min-w-0 flex-1 leading-tight group-data-[collapsible=icon]:hidden">
                      <span className="block truncate text-sm font-semibold" title={application.name}>
                        {application.name}
                      </span>
                      {/* Status, then what it is: "Running · WordPress". */}
                      <span className="mt-1 flex min-w-0 items-center gap-1 text-xs text-muted-foreground">
                        <ApplicationStatusDot application={application} className="shrink-0" />
                        {application.site_type_title ? (
                          <span className="truncate">· {application.site_type_title}</span>
                        ) : null}
                      </span>
                    </span>
                  </span>
                  {/* Its own full-width line, in a box: the domain is often the longest string and
                      would truncate mid-host beside the name. */}
                  {/* Right padding keeps the domain clear of the open-site icon drawn over this row. */}
                  <span className="mt-2.5 block w-full truncate rounded-lg bg-background/80 py-1.5 pr-8 pl-2.5 font-mono text-xs text-muted-foreground ring-1 ring-border/60 group-data-[collapsible=icon]:hidden">
                    {application.domain}
                  </span>
                </Link>
              </MobileNavLink>
              {/* Sibling, not child: anchors cannot nest. Only while the site is served
                  (a provisioning site would show a connection error). */}
              {application.status === "active" && application.url ? (
                <VisitSiteLink
                  href={application.url}
                  label={t("applicationContext.visit", { domain: application.domain })}
                  // On the domain row's right end, as in the redesign, not over the name.
                  className="absolute right-3.5 bottom-3.5 group-data-[collapsible=icon]:hidden"
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
        {groups.map((group) => {
          const title = groupTitle(group);
          // Icon-only rail has no labels, so nothing in it may be hidden.
          const open =
            iconOnly ||
            !title ||
            (toggled[group.key] ??
              (!COLLAPSED_GROUPS.has(group.key) || group.items.includes(activeItem)));
          return (
          <SidebarGroup key={group.key} className="py-1">
            {title ? (
              <SidebarGroupLabel asChild className="text-[13px] font-semibold text-muted-foreground hover:text-foreground">
                <button
                  type="button"
                  aria-expanded={open}
                  onClick={() => setToggled((current) => ({ ...current, [group.key]: !open }))}
                  className="w-full justify-between"
                >
                  {title}
                  <ChevronDown className={cn("transition-transform", !open && "-rotate-90")} aria-hidden />
                </button>
              </SidebarGroupLabel>
            ) : null}
            {open ? (
            <SidebarMenu className="gap-0.5">
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
                      {/* No prefetch: each one is a full server render against the API's rate
                          limit, and the whole menu is always on screen. */}
                      <Link href={item.href} prefetch={false}>
                        <PendingNavIcon name={item.icon} />
                        <span>{navTitle(item, t)}</span>
                      </Link>
                    </MobileNavLink>
                  </SidebarMenuItem>
                );
              })}
            </SidebarMenu>
            ) : null}
          </SidebarGroup>
          );
        })}
        </SidebarLevelTransition>
      </SidebarContent>
      <SidebarRail />
    </Sidebar>
  );
}
