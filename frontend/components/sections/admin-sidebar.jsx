"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { ADMIN_NAV, isAdminNavActive } from "@/lib/admin-nav";
import { NAV_ITEM_CLASS } from "@/lib/navigation";
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

export function AdminSidebar() {
  const pathname = usePathname();
  const t = useTranslations("admin");
  const { state, isMobile, setOpenMobile } = useSidebar();
  const iconOnly = state === "collapsed" && !isMobile;

  return (
    <Sidebar collapsible="icon">
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
        <SidebarGroup className="py-1">
          <SidebarGroupLabel className="text-xs font-medium uppercase tracking-wider text-muted-foreground/70">
            {t("breadcrumbRoot")}
          </SidebarGroupLabel>
          <SidebarMenu className="gap-1.5">
            {ADMIN_NAV.map((item) => {
              const active = isAdminNavActive(pathname, item.url);
              const title = t(`nav.${item.key}`);
              return (
                <SidebarMenuItem key={item.url}>
                  <SidebarMenuButton
                    asChild
                    isActive={active}
                    tooltip={title}
                    className={NAV_ITEM_CLASS}
                    onClick={() => isMobile && setOpenMobile(false)}
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
      </SidebarContent>
      <SidebarRail />
    </Sidebar>
  );
}
