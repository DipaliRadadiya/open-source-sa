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

export function AdminHeader() {
  // `usePathname` is kept as a subscription: dropping it stops this header
  // re-rendering on navigation.
  // eslint-disable-next-line no-unused-vars -- pending a decision; see above
  const pathname = usePathname();
  const t = useTranslations("admin");
  const { guardNavigation } = useUnsaved();

  return (
    // Stickiness is owned by the wrapping cluster in the layout, so the breadcrumb
    // band pins with it; this stays a plain bar.
    <header className="flex h-16 shrink-0 items-center gap-2 border-b bg-background/95 px-4 backdrop-blur supports-[backdrop-filter]:bg-background/60 sm:px-6 max-sm:[&_button]:min-h-11 max-sm:[&_button]:min-w-11">
      <SidebarToggle />
      <div className="ml-auto flex items-center gap-2">
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
