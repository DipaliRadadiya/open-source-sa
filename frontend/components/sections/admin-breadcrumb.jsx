"use client";

import Link from "next/link";
import { usePathname } from "next/navigation";
import { useTranslations } from "next-intl";
import { ADMIN_NAV, isAdminNavActive } from "@/lib/admin-nav";
import { useUnsaved } from "@/components/ui/unsaved-guard";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbLink,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@/components/ui/breadcrumb";

/**
 * The admin panel's trail, lifted out of the header bar.
 *
 * It was inline in `AdminHeader`; it now sits at the top of the page content
 * like the server panel's does, so the two panels read the same way — including
 * a root that is a link rather than a label.
 */
export function AdminBreadcrumb() {
  const pathname = usePathname();
  const t = useTranslations("admin");
  const { guardNavigation } = useUnsaved();
  const current = ADMIN_NAV.find((item) => isAdminNavActive(pathname, item.url));
  const atRoot = pathname === "/admin";

  return (
    <Breadcrumb>
      <BreadcrumbList className="gap-1.5 sm:gap-2">
        <BreadcrumbItem>
          {atRoot ? (
            <span>{t("breadcrumbRoot")}</span>
          ) : (
            <BreadcrumbLink asChild>
              <Link
                href="/admin"
                onClick={(event) => {
                  if (guardNavigation("/admin")) event.preventDefault();
                }}
                className="rounded-sm focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2"
              >
                {t("breadcrumbRoot")}
              </Link>
            </BreadcrumbLink>
          )}
        </BreadcrumbItem>
        {current && (
          <>
            <BreadcrumbSeparator className="text-muted-foreground/50" />
            <BreadcrumbItem>
              <BreadcrumbPage>{t(`nav.${current.key}`)}</BreadcrumbPage>
            </BreadcrumbItem>
          </>
        )}
      </BreadcrumbList>
    </Breadcrumb>
  );
}
