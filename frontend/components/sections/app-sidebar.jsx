"use client";

import Link from "next/link";
import { usePathname, useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import {
  groupBySubLevel,
  isNavBuilt,
  findActiveNavItem,
  resolveNavItems,
  NAV_ITEM_CLASS,
} from "@/lib/navigation";
import { useApplicationNav } from "@/components/sections/application-nav";
import { Logo } from "@/components/logo";
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

/** Closes the mobile sidebar sheet after a nav link click. */
function MobileNavLink({ item, built, active, children }) {
  const { isMobile, setOpenMobile } = useSidebar()
  const t = useTranslations("common")
  const handleClick = () => { if (isMobile) setOpenMobile(false) }
  if (!built) {
    return (
      <SidebarMenuButton
        asChild
        tooltip={`${item.title} · ${t("soon")}`}
        className={cn(NAV_ITEM_CLASS, "cursor-default text-muted-foreground/55 hover:bg-transparent hover:text-muted-foreground/55")}
        onClick={handleClick}
      >
        <span aria-disabled="true">
          {children}
          <span className="ml-auto rounded-sm bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground/70 group-data-[collapsible=icon]:hidden">
            {t("soon")}
          </span>
        </span>
      </SidebarMenuButton>
    )
  }
  return (
    <SidebarMenuButton
      asChild
      isActive={active}
      tooltip={item.title}
      className={NAV_ITEM_CLASS}
      onClick={handleClick}
    >
      {children}
    </SidebarMenuButton>
  )
}

export function AppSidebar({ items }) {
  const pathname = usePathname();
  const params = useParams();
  const t = useTranslations("common");
  const { state, isMobile } = useSidebar();

  const applicationId = params?.application;
  const iconOnly = state === "collapsed" && !isMobile;

  // Inside an application, prefer the catalog its layout fetched: only that one
  // is filtered by what this site type supports. Until it arrives, the shared
  // catalog renders the same items minus that filter.
  const { items: applicationItems, resolved } = useApplicationNav();
  // Once the layout has answered and the answer is "no menu", this site does not
  // exist. Fall back to the SERVER panel rather than rendering the shared
  // catalog against a dead id — that produced a full site menu whose every link
  // 404s, on a page telling you the site could not be found.
  const insideApplication = Boolean(applicationId) && (applicationItems !== null || !resolved);
  const currentPanel = insideApplication ? "application" : "server";
  const source = insideApplication ? (applicationItems ?? items) : items;

  // Every application screen the catalog advertises is shown, so the sidebar is
  // the site's full feature map. The ones whose route hasn't shipped yet render
  // as non-clickable "Soon" rows rather than being hidden or 404ing.
  const visible = resolveNavItems(source, applicationId)
    .filter((item) => item?.permissions?.view)
    .filter((item) => item.level === currentPanel);

  const groups = groupBySubLevel(visible);

  // Longest match wins: the application Dashboard's href (`/applications/{id}`)
  // is a prefix of every sub-page, so a per-item prefix check lights it up
  // everywhere. Pick the single deepest-matching item instead.
  const activeItem = findActiveNavItem(visible, pathname);

  return (
    <Sidebar collapsible="icon">
      <SidebarHeader className="h-16 justify-center border-b px-3">
        <Link href="/dashboard" className="flex items-center">
          {iconOnly ? (
            <Logo collapsed className="size-8" />
          ) : (
            <Logo className="h-8 w-auto" />
          )}
        </Link>
      </SidebarHeader>
      <SidebarContent className="gap-0 py-2">
        {groups.map((group) => (
          <SidebarGroup key={group.key} className="py-1">
            {group.key && (
              <SidebarGroupLabel className="text-xs font-medium uppercase tracking-wider text-muted-foreground/70">
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
                        <span>{item.title}</span>
                      </MobileNavLink>
                    </SidebarMenuItem>
                  )
                }

                return (
                  <SidebarMenuItem key={`${item.name}-${item.href}`}>
                    <MobileNavLink item={item} built active={active}>
                      {/* Every one of these routes is dynamic and cookie-gated,
                          so a prefetch is a full server render against the API
                          — and the whole menu is on screen at all times. Left
                          on, opening one page fired ~16 background renders and
                          burned most of the API's per-minute budget before the
                          user clicked anything. Intent (hover/touch) still
                          prefetches; `loading.jsx` covers the rest. */}
                      <Link href={item.href} prefetch={false}>
                        <NavIcon name={item.icon} />
                        <span>{item.title}</span>
                      </Link>
                    </MobileNavLink>
                  </SidebarMenuItem>
                );
              })}
            </SidebarMenu>
          </SidebarGroup>
        ))}
      </SidebarContent>
      <SidebarRail />
    </Sidebar>
  );
}
