"use client";

import Link from "@/components/ui/app-link";
import { Shield, UserCog } from "lucide-react";
import { useTranslations } from "next-intl";
import { useUser } from "@/hooks/use-user";
import { SidebarToggle } from "@/components/sections/sidebar-toggle";
import { ThemeToggle } from "@/components/theme-toggle";
import { LocaleSwitcher } from "@/components/sections/locale-switcher";
import { UserMenu } from "@/components/sections/user-menu";
import { DropdownMenuItem } from "@/components/ui/dropdown-menu";
import { useUnsaved } from "@/components/ui/unsaved-guard";

export function AppHeader({ impersonating = false }) {
  const user = useUser();
  const t = useTranslations("admin");
  const tAccount = useTranslations("account");
  const { guardNavigation } = useUnsaved();
  const isAdmin = user?.is_admin;

  return (
    // Stickiness belongs to the layout's wrapping cluster. Small screens get a 44px
    // touch box without changing the controls' visual size.
    <header className="flex h-16 shrink-0 items-center gap-2 border-b bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/60 sm:px-6 max-sm:[&_button]:min-h-11 max-sm:[&_button]:min-w-11">
      {/* The trail lives at the top of the page content, where it has the page's width. */}
      <SidebarToggle />
      <div className="ml-auto flex items-center gap-2">
        <LocaleSwitcher />
        <ThemeToggle />
        <UserMenu
          impersonating={impersonating}
          extraItems={
            <>
              <DropdownMenuItem asChild>
                <Link
                  href="/account"
                  onClick={(event) => {
                    if (guardNavigation("/account")) event.preventDefault();
                  }}
                >
                  <UserCog className="size-4" />
                  {tAccount("title")}
                </Link>
              </DropdownMenuItem>
              {isAdmin && (
                <DropdownMenuItem asChild>
                  <Link
                    href="/admin"
                    onClick={(event) => {
                      if (guardNavigation("/admin")) event.preventDefault();
                    }}
                  >
                    <Shield className="size-4" />
                    {t("switchToAdmin")}
                  </Link>
                </DropdownMenuItem>
              )}
            </>
          }
        />
      </div>
    </header>
  );
}
