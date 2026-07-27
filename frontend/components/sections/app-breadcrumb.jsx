"use client";

import { usePathname, useParams } from "next/navigation";
import {
  Breadcrumb,
  BreadcrumbItem,
  BreadcrumbList,
  BreadcrumbPage,
  BreadcrumbSeparator,
} from "@/components/ui/breadcrumb";

export function AppBreadcrumb({ items }) {
  const pathname = usePathname();
  const params = useParams();
  const panel = params?.application ? "Application" : "Server";

  const current = (items || []).find((item) => item.url === pathname);
  const title = current?.title;

  return (
    <Breadcrumb>
      <BreadcrumbList>
        <BreadcrumbItem className="text-muted-foreground">{panel}</BreadcrumbItem>
        {title && (
          <>
            <BreadcrumbSeparator />
            <BreadcrumbItem>
              <BreadcrumbPage>{title}</BreadcrumbPage>
            </BreadcrumbItem>
          </>
        )}
      </BreadcrumbList>
    </Breadcrumb>
  );
}
