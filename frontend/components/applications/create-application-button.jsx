import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { Plus } from "lucide-react";
import { Button } from "@/components/ui/button";

// Hidden from roles that cannot create applications.
export function CreateApplicationButton({ canManage = false }) {
  const t = useTranslations("applications");
  return canManage ? (
    <Button asChild>
      <Link href="/applications/create">
        <Plus className="size-4" />
        {t("create")}
      </Link>
    </Button>
  ) : null;
}
