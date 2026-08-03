"use client";

import { useSearchParams } from "next/navigation";
import { Plus } from "lucide-react";
import { useTranslations } from "next-intl";
import { SearchInput } from "@/components/data-table/search-input";
import { RefreshButton } from "@/components/data-table/refresh-button";
import { useSetQuery } from "@/hooks/use-set-query";
import { useCreateUser } from "@/components/admin/users/users-view";
import { Button } from "@/components/ui/button";
import {
  Select,
  SelectContent,
  SelectItem,
  SelectTrigger,
  SelectValue,
} from "@/components/ui/select";

export function UsersToolbar() {
  const t = useTranslations("users");
  const setQuery = useSetQuery();
  const { openCreate } = useCreateUser();
  const searchParams = useSearchParams();
  const roleValue = searchParams.get("is_admin") ?? "all";

  return (
    <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div className="flex flex-1 flex-col gap-3 sm:flex-row sm:items-center">
        <SearchInput placeholder={t("searchPlaceholder")} />
        <Select
          value={roleValue}
          onValueChange={(v) =>
            setQuery({ is_admin: v === "all" ? undefined : v }, { resetPage: true })
          }
        >
          <SelectTrigger className="w-full sm:w-44">
            <SelectValue />
          </SelectTrigger>
          <SelectContent>
            <SelectItem value="all">{t("filter.allAccounts")}</SelectItem>
            <SelectItem value="1">{t("filter.admins")}</SelectItem>
            <SelectItem value="0">{t("filter.nonAdmins")}</SelectItem>
          </SelectContent>
        </Select>
      </div>

      <div className="flex items-center gap-2">
        <RefreshButton />
        <Button onClick={openCreate}>
          <Plus className="size-4" />
          {t("addUser")}
        </Button>
      </div>
    </div>
  );
}
