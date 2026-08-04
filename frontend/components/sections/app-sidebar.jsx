"use client";

import Link from "next/link";
import { usePathname, useParams } from "next/navigation";
import {
  groupBySubLevel,
  isApplicationNavBuilt,
  isNavActive,
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
} from "@/components/ui/sidebar";

export function AppSidebar({ items }) {
  const pathname = usePathname();
  const params = useParams();
  const { state, isMobile } = useSidebar();

  const applicationId = params?.application;
  const currentPanel = applicationId ? "application" : "server";
  const iconOnly = state === "collapsed" && !isMobile;

  // Inside an application, prefer the catalog its layout fetched: only that one
  // is filtered by what this site type supports. Until it arrives, the shared
  // catalog renders the same items minus that filter.
  const { items: applicationItems } = useApplicationNav();
  const source = currentPanel === "application" ? (applicationItems ?? items) : items;

  const visible = resolveNavItems(source, applicationId)
    .filter((item) => item?.permissions?.view)
    .filter((item) => item.level === currentPanel)
    .filter((item) => currentPanel !== "application" || isApplicationNavBuilt(item.url));

  const groups = groupBySubLevel(visible);

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
        {Object.entries(groups).map(([subLevel, groupItems]) => (
          <SidebarGroup key={subLevel} className="py-1">
            {subLevel && (
              <SidebarGroupLabel className="text-xs font-medium uppercase tracking-wider text-muted-foreground/70">
                {subLevel}
              </SidebarGroupLabel>
            )}
            <SidebarMenu className="gap-1.5">
              {groupItems.map((item) => {
                const active = isNavActive(pathname, item.href);
                return (
                  <SidebarMenuItem key={`${item.name}-${item.href}`}>
                    <SidebarMenuButton
                      asChild
                      isActive={active}
                      tooltip={item.title}
                      className={NAV_ITEM_CLASS}
                    >
                      <Link href={item.href}>
                        <NavIcon name={item.icon} />
                        <span>{item.title}</span>
                      </Link>
                    </SidebarMenuButton>
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
