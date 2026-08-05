"use client";

import Link from "next/link";
import { usePathname, useParams } from "next/navigation";
import { useTranslations } from "next-intl";
import { cn } from "@/lib/utils";
import {
  groupBySubLevel,
  isApplicationNavBuilt,
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
} from "@/components/ui/sidebar";

export function AppSidebar({ items }) {
  const pathname = usePathname();
  const params = useParams();
  const t = useTranslations("common");
  const { state, isMobile } = useSidebar();

  const applicationId = params?.application;
  const currentPanel = applicationId ? "application" : "server";
  const iconOnly = state === "collapsed" && !isMobile;

  // Inside an application, prefer the catalog its layout fetched: only that one
  // is filtered by what this site type supports. Until it arrives, the shared
  // catalog renders the same items minus that filter.
  const { items: applicationItems } = useApplicationNav();
  const source = currentPanel === "application" ? (applicationItems ?? items) : items;

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
        {Object.entries(groups).map(([subLevel, groupItems]) => (
          <SidebarGroup key={subLevel} className="py-1">
            {subLevel && (
              <SidebarGroupLabel className="text-xs font-medium uppercase tracking-wider text-muted-foreground/70">
                {subLevel}
              </SidebarGroupLabel>
            )}
            <SidebarMenu className="gap-1.5">
              {groupItems.map((item) => {
                const built =
                  currentPanel !== "application" ||
                  isApplicationNavBuilt(item.url);
                const active = item === activeItem;

                if (!built) {
                  return (
                    <SidebarMenuItem key={`${item.name}-${item.href}`}>
                      <SidebarMenuButton
                        asChild
                        tooltip={`${item.title} · ${t("soon")}`}
                        className={cn(
                          NAV_ITEM_CLASS,
                          "cursor-default text-muted-foreground/55 hover:bg-transparent hover:text-muted-foreground/55",
                        )}
                      >
                        <span aria-disabled="true">
                          <NavIcon name={item.icon} />
                          <span>{item.title}</span>
                          <span className="ml-auto rounded-sm bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground/70 group-data-[collapsible=icon]:hidden">
                            {t("soon")}
                          </span>
                        </span>
                      </SidebarMenuButton>
                    </SidebarMenuItem>
                  );
                }

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
