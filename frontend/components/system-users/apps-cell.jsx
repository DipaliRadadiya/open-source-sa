import { useState } from "react";
import { useTranslations } from "next-intl";
import { Badge } from "@/components/ui/badge";
import { SystemUserAppsDialog } from "@/components/system-users/system-user-apps-dialog";

// The Apps count in the table; clickable (opens a modal) when the user owns any.
export function AppsCell({ user }) {
  const t = useTranslations("systemUsers");
  const [open, setOpen] = useState(false);
  const count = user.applications?.length ?? 0;

  if (count === 0) {
    return (
      <Badge variant="outline" className="font-normal text-muted-foreground">
        {t("appsCount", { count: 0 })}
      </Badge>
    );
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
          className="cursor-pointer font-normal hover:bg-muted"
        >
          {t("appsCount", { count })}
        </Badge>
      </button>
      <SystemUserAppsDialog user={user} open={open} onOpenChange={setOpen} />
    </>
  );
}
