"use client";

import Link from "@/components/ui/app-link";
import { useState } from "react";
import { useLinkStatus } from "next/link";
import { usePathname, useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { ArrowLeft, ChevronDown, ChevronsUpDown, Loader2 } from "lucide-react";
import { SiteTypeLogo } from "@/components/applications/site-type-logo";
import { cn } from "@/lib/utils";
import {
  groupNavItems,
  COLLAPSED_GROUPS,
  SIDEBAR_GROUPS_COOKIE,
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
import { ApplicationSwitcher } from "@/components/sections/application-switcher";
import { can } from "@/lib/permissions/can";
import { landingPath } from "@/lib/permissions/landing-path";
import { Skeleton } from "@/components/ui/skeleton";
import { HoverHint } from "@/components/ui/hover-hint";
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

// beforeunload doesn't see client-side navigation, so unsaved edits are guarded here.
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

function PendingNavIcon({ name }) {
  const { pending } = useLinkStatus();

  return pending ? <Loader2 className="size-4 animate-spin" /> : <NavIcon name={name} />;
}

export function AppSidebar({ items, initialGroups = {} }) {
  const home = landingPath(items);
  const pathname = usePathname();
  const params = useParams();
  const t = useTranslations("common");
  const { state, isMobile } = useSidebar();
  const { guardNavigation } = useUnsaved();

  const applicationId = params?.application;
  const iconOnly = state === "collapsed" && !isMobile;

  const { items: applicationItems, resolved, application, gitProvider, iconDataUri } = useApplicationNav();
  const insideApplication = Boolean(applicationId) && (applicationItems !== null || !resolved);
  const pendingApplication = Boolean(applicationId) && !resolved;
  const currentPanel = insideApplication ? "application" : "server";
  const source = insideApplication ? (applicationItems ?? items) : items;

  const visible = resolveNavItems(source, applicationId)
    .filter((item) => item?.permissions?.view)
    .filter((item) => item.level === currentPanel);

  const groups = groupNavItems(visible);

  const activeItem = findActiveNavItem(visible, pathname);

  const [toggled, setToggled] = useState(initialGroups);
  function toggle(key, open) {
    const next = { ...toggled, [key]: open };
    setToggled(next);
    document.cookie = `${SIDEBAR_GROUPS_COOKIE}=${encodeURIComponent(JSON.stringify(next))}; path=/; max-age=31536000; samesite=lax`;
  }
  const groupTitle = (group) =>
    group.named ? t(`navGroups.${group.key === "protection" ? "security" : group.key}`) : group.title;

  return (
    <Sidebar collapsible="icon" label={t("serverNavigation")}>
      <SidebarHeader className="h-16 justify-center border-b px-3">
        <Link
          href={home}
          className="flex items-center"
          onClick={(event) => {
            if (pathname !== home && guardNavigation(home)) event.preventDefault();
          }}
        >
          {iconOnly ? (
            <Logo collapsed className="size-8" />
          ) : (
            <Logo className="h-8 w-auto" />
          )}
        </Link>
      </SidebarHeader>
      {!insideApplication && can(items, "dashboard", "view") ? (
        <SidebarGroup className="px-2 pt-3 pb-0 group-data-[collapsible=icon]:hidden">
          <SidebarServerCard />
        </SidebarGroup>
      ) : null}
      {pendingApplication ? (
        <SidebarGroup className="border-b px-2 pt-2 pb-4" aria-hidden>
          <Skeleton className="mb-1 h-8 w-40" />
          <Skeleton className="h-20 w-full rounded-[2px] group-data-[collapsible=icon]:h-8" />
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
              <SidebarMenuButton
                asChild
                tooltip={t("applicationContext.switch")}
                className={cn(NAV_ITEM_CLASS, "relative h-auto min-h-20 items-start overflow-hidden rounded-[2px] border border-primary/70 bg-card p-3 shadow-none transition-colors hover:border-primary hover:bg-card active:bg-card data-[active=true]:bg-card data-[state=open]:border-primary group-data-[collapsible=icon]:min-h-8! group-data-[collapsible=icon]:p-2!")}
              >
                <ApplicationSwitcher application={application} aria-label={t("applicationContext.switchNamed", { name: application.name })}>
                  <span className="flex w-full min-w-0 flex-col items-stretch gap-0 text-left">
                    <span className="relative flex w-full min-w-0 items-center gap-2.5">
                      <span className="flex size-9 shrink-0 items-center justify-center rounded-[2px] border border-primary/60 group-data-[collapsible=icon]:size-5! group-data-[collapsible=icon]:border-0!">
                        <SiteTypeLogo name={application.site_type} provider={gitProvider} iconDataUri={iconDataUri} size="h-5 w-5" />
                      </span>
                      <span className="min-w-0 flex-1 leading-tight group-data-[collapsible=icon]:hidden">
                        <HoverHint content={application.name}><span className="block truncate font-mono text-sm font-semibold text-foreground">
                          {application.name}
                        </span></HoverHint>
                        <span className="mt-1 flex min-w-0 items-center gap-1 font-mono text-xs text-muted-foreground">
                          <ApplicationStatusDot application={application} className="shrink-0 [&>span:first-child]:rounded-none" labelClassName="text-xs" />
                          {application.site_type_title ? (
                            <span className="truncate">· {application.site_type_title}</span>
                          ) : null}
                        </span>
                      </span>
                      <ChevronsUpDown className="size-4 shrink-0 self-start text-muted-foreground group-data-[collapsible=icon]:hidden" aria-hidden />
                    </span>
                    <span className="mt-2.5 block w-full truncate rounded-[2px] border bg-background py-1.5 pr-8 pl-2.5 font-mono text-xs text-muted-foreground group-data-[collapsible=icon]:hidden">
                      {application.domain}
                    </span>
                  </span>
                </ApplicationSwitcher>
              </SidebarMenuButton>
              {/* Sibling, not child: anchors can't nest. */}
              {application.status === "active" && application.url ? (
                <VisitSiteLink
                  href={application.url}
                  label={t("applicationContext.visit", { domain: application.domain })}
                  className="absolute right-3.5 bottom-3.5 group-data-[collapsible=icon]:hidden"
                />
              ) : null}
            </SidebarMenuItem>
          </SidebarMenu>
        </SidebarGroup>
      ) : null}
      <SidebarContent className="gap-0 pt-4 pb-2">
        <SidebarLevelTransition level={insideApplication ? `application:${applicationId}` : "server"}>
        {pendingApplication ? <PendingMenu /> : null}
        {pendingApplication ? null : groups.map((group) => {
          const title = groupTitle(group);
          const open =
            iconOnly ||
            !title ||
            (toggled[`${currentPanel}:${group.key}`] ??
              (!COLLAPSED_GROUPS.has(group.key) || group.items.includes(activeItem)));
          return (
          <SidebarGroup key={group.key} className="py-1">
            {title ? (
              <SidebarGroupLabel asChild className="text-[13px] font-semibold text-muted-foreground hover:text-foreground">
                <button
                  type="button"
                  aria-expanded={open}
                  onClick={() => toggle(`${currentPanel}:${group.key}`, !open)}
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

const PENDING_ROWS = ["w-24", "w-28", "w-20", "w-16", "w-12", "w-24", "w-32", "w-28"];

function PendingMenu() {
  return (
    <SidebarGroup aria-busy="true">
      <SidebarMenu className="gap-1">
        {PENDING_ROWS.map((width, index) => (
          <SidebarMenuItem key={index} className="flex h-8 items-center gap-2 px-2">
            <Skeleton className="size-4 shrink-0 rounded-md" />
            <Skeleton className={`h-3.5 ${width} group-data-[collapsible=icon]:hidden`} />
          </SidebarMenuItem>
        ))}
      </SidebarMenu>
    </SidebarGroup>
  );
}
