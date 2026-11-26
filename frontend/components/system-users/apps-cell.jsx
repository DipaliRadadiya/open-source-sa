import { useState } from "react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { SystemUserAppsDialog } from "@/components/system-users/system-user-apps-dialog";

export function AppsCell({ user }) {
  const t = useTranslations("systemUsers");
  const [open, setOpen] = useState(false);
  const count = user.applications?.length ?? 0;

  if (count === 0) {
    return <span className="text-xs text-muted-foreground">{t("appsCount", { count: 0 })}</span>;
  }

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        className="rounded-full outline-none focus-visible:ring-2 focus-visible:ring-ring/50"
      >
        <Badge
          variant="outline"
          className="h-auto cursor-pointer font-normal whitespace-normal hover:bg-muted"
        >
          {t("appsCount", { count })}
        </Badge>
      </button>
      <SystemUserAppsDialog user={user} open={open} onOpenChange={setOpen} />
    </>
  );
}
