import Link from "@/components/ui/app-link";
import { useTranslations } from "next-intl";
import { ScrollText } from "lucide-react";
import {
  DropdownMenuItem,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from "@/components/ui/dropdown-menu";

// Several sources become a submenu. The API only lists sources that exist on the box.
export function ServiceLogItems({ service }) {
  const t = useTranslations("services");
  const keys = service.log_keys ?? [];

  if (keys.length === 0) return null;

  if (keys.length === 1) {
    return (
      <DropdownMenuItem asChild>
        <Link href={`/logs?source=${encodeURIComponent(keys[0])}`}>
          <ScrollText className="size-4" />
          {t("viewLogs")}
        </Link>
      </DropdownMenuItem>
    );
  }

  return (
    <DropdownMenuSub>
      <DropdownMenuSubTrigger>
        <ScrollText className="size-4" />
        {t("viewLogs")}
      </DropdownMenuSubTrigger>
      <DropdownMenuSubContent>
        {keys.map((key) => (
          <DropdownMenuItem key={key} asChild>
            <Link href={`/logs?source=${encodeURIComponent(key)}`}>
              <ScrollText className="size-4" />
              {/* The raw key: the Logs page owns the friendly labels. */}
              <span className="font-mono text-xs">{key}</span>
            </Link>
          </DropdownMenuItem>
        ))}
      </DropdownMenuSubContent>
    </DropdownMenuSub>
  );
}
