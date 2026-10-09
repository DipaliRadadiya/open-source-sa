import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";

// Shown disabled to viewers, with the reason, like other pages' primary action.
export function CreateApplicationButton({ canManage = false }) {
  const t = useTranslations("applications");
  return canManage ? (
    <Button asChild>
      <Link href="/applications/create">
        <Plus className="size-4" />
        {t("create")}
      </Link>
    </Button>
  ) : (
    <ReasonTooltip reason={t("noPermission")}>
      <Button disabled>
        <Plus className="size-4" />
        {t("create")}
      </Button>
    </ReasonTooltip>
  );
}
