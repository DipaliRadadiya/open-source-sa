"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { ArrowLeft } from "lucide-react";
import { ADMIN_NAV, isAdminNavActive } from "@/lib/admin-nav";
import { SidebarToggle } from "@/components/sections/sidebar-toggle";
import { ThemeToggle } from "@/components/theme-toggle";
import { LocaleSwitcher } from "@/components/sections/locale-switcher";
import { UserMenu } from "@/components/sections/user-menu";
import { DropdownMenuItem } from "@/components/ui/dropdown-menu";

export function AdminHeader() {
  const pathname = usePathname();
  const t = useTranslations("admin");

  const current = ADMIN_NAV.find((item) => isAdminNavActive(pathname, item.url));

  return (
    // Stickiness is owned by the wrapping cluster in the layout, so the
    // breadcrumb band pins with it — this stays a plain bar.
    <header className="flex h-16 shrink-0 items-center gap-2 border-b bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/60 sm:px-6">
      <SidebarToggle />
      <div className="ml-auto flex items-center gap-2">
        <LocaleSwitcher />
        <ThemeToggle />
        <UserMenu
          extraItems={
            <DropdownMenuItem asChild>
              <Link href="/dashboard">
                <ArrowLeft className="size-4" />
                {t("exitToPanel")}
              </Link>
            </DropdownMenuItem>
          }
        />
      </div>
    </header>
  );
}
