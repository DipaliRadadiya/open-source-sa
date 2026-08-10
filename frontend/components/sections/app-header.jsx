"use client";

import Link from "next/link";
import { Shield, UserCog } from "lucide-react";
import { useTranslations } from "next-intl";
import { useUser } from "@/hooks/use-user";
import { SidebarToggle } from "@/components/sections/sidebar-toggle";
import { ThemeToggle } from "@/components/theme-toggle";
import { LocaleSwitcher } from "@/components/sections/locale-switcher";
import { UserMenu } from "@/components/sections/user-menu";
import { DropdownMenuItem } from "@/components/ui/dropdown-menu";

export function AppHeader({ impersonating = false }) {
  const user = useUser();
  const t = useTranslations("admin");
  const tAccount = useTranslations("account");
  const isAdmin = user?.is_admin;

  return (
    // Stickiness is owned by the wrapping cluster in the layout (so an
    // impersonation banner can pin above it) — this stays a plain bar.
    <header className="flex h-16 shrink-0 items-center gap-2 border-b bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/60 sm:px-6">
      {/* The trail lives at the top of the page content, not up here. In the
          bar it competed with the account controls for a fixed 64px and had to
          hide its ancestors on narrow screens to fit; in the content column it
          has the page's own width and sits directly above the thing it
          describes. */}
      <SidebarToggle />
      <div className="ml-auto flex items-center gap-2">
        <LocaleSwitcher />
        <ThemeToggle />
        <UserMenu
          impersonating={impersonating}
          extraItems={
            <>
              <DropdownMenuItem asChild>
                <Link href="/account">
                  <UserCog className="size-4" />
                  {tAccount("title")}
                </Link>
              </DropdownMenuItem>
              {isAdmin && (
                <DropdownMenuItem asChild>
                  <Link href="/admin">
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
