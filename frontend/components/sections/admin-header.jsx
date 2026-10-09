"use client";

import Link from "@/components/ui/app-link";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { ArrowLeft } from "lucide-react";
import { SidebarToggle } from "@/components/sections/sidebar-toggle";
import { ThemeToggle } from "@/components/theme-toggle";
import { LocaleSwitcher } from "@/components/sections/locale-switcher";
import { UserMenu } from "@/components/sections/user-menu";
import { DropdownMenuItem } from "@/components/ui/dropdown-menu";
import { useUnsaved } from "@/components/ui/unsaved-guard";

export function AdminHeader({ breadcrumb = null }) {
  // `usePathname` is kept as a subscription: dropping it stops this header
  // re-rendering on navigation.
  // eslint-disable-next-line no-unused-vars -- pending a decision; see above
  const pathname = usePathname();
  const t = useTranslations("admin");
  const { guardNavigation } = useUnsaved();

  return (
    // The server panel's bar: the trail sits beside the controls, not in a band below.
    <header className="flex h-16 shrink-0 items-center gap-3 border-b bg-background/80 px-4 backdrop-blur-xl supports-[backdrop-filter]:bg-background/70 sm:px-6 max-sm:[&_button]:min-h-11 max-sm:[&_button]:min-w-11">
      <SidebarToggle />
      <div className="min-w-0 flex-1 max-sm:invisible">{breadcrumb}</div>
      <div className="flex shrink-0 items-center gap-2">
        <LocaleSwitcher />
        <ThemeToggle />
        <UserMenu
          extraItems={
            <DropdownMenuItem asChild>
              <Link
                href="/dashboard"
                onClick={(event) => {
                  if (guardNavigation("/dashboard")) event.preventDefault();
                }}
              >
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
