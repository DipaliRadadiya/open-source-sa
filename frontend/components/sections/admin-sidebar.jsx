"use client";

import Link from "@/components/ui/app-link";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { ADMIN_NAV, isAdminNavActive } from "@/lib/admin-nav";
import { NAV_ITEM_CLASS } from "@/lib/navigation";
import { Logo } from "@/components/logo";
import { NavIcon } from "@/components/nav-icon";
import { useUnsaved } from "@/components/ui/unsaved-guard";
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

// In menu order; a group starts where the first of its items appears.
const ADMIN_GROUPS = ADMIN_NAV.reduce((groups, item) => {
  const key = item.group ?? "";
  const last = groups.at(-1);
  if (last && last.key === key) last.items.push(item);
  else groups.push({ key, items: [item] });
  return groups;
}, []);

export function AdminSidebar() {
  const pathname = usePathname();
  const t = useTranslations("admin");
  const { state, isMobile, setOpenMobile } = useSidebar();
  const { guardNavigation } = useUnsaved();
  const iconOnly = state === "collapsed" && !isMobile;

  return (
    <Sidebar collapsible="icon" label={t("navigationLabel")}>
      <SidebarHeader className="h-16 justify-center border-b px-3">
        <Link href="/admin" className="flex items-center">
          {iconOnly ? (
            <Logo collapsed className="size-8" />
          ) : (
            <Logo className="h-8 w-auto" />
          )}
        </Link>
      </SidebarHeader>
      <SidebarContent className="gap-0 py-2">
        {/* Grouped like the server menu: Dashboard on its own, then labelled groups. */}
        {ADMIN_GROUPS.map((group) => (
          <SidebarGroup key={group.key || "top"} className="py-1">
            {group.key ? (
              <SidebarGroupLabel className="text-[13px] font-semibold text-muted-foreground">
                {t(`navGroups.${group.key}`)}
              </SidebarGroupLabel>
            ) : null}
            <SidebarMenu className="gap-1.5">
              {group.items.map((item) => {
                const active = isAdminNavActive(pathname, item.url);
                const title = t(`nav.${item.key}`);
                return (
                  <SidebarMenuItem key={item.url}>
                    <SidebarMenuButton
                      asChild
                      isActive={active}
                      tooltip={title}
                      className={NAV_ITEM_CLASS}
                      onClick={(event) => {
                        if (
                          !active &&
                          guardNavigation(item.url, () => isMobile && setOpenMobile(false))
                        ) {
                          event.preventDefault();
                          return;
                        }
                        if (isMobile) setOpenMobile(false);
                      }}
                    >
                      <Link href={item.url}>
                        <NavIcon name={item.icon} />
                        <span>{title}</span>
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
