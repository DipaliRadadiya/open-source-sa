import Link from "next/link";
import { useTranslations } from "next-intl";
import { ScrollText } from "lucide-react";
import {
  DropdownMenuItem,
  DropdownMenuSub,
  DropdownMenuSubContent,
  DropdownMenuSubTrigger,
} from "@/components/ui/dropdown-menu";

/**
 * This service's logs, as items in the row's actions menu.
 *
 * The whole point of a failed row is finding out why, and until this existed
 * that meant leaving for the Logs page and guessing which file belonged to the
 * thing that broke.
 *
 * One source → one item. Several (nginx has error and access) → a submenu,
 * because picking the wrong one wastes the trip.
 *
 * This was an icon button on the row. It is a menu item now for the reason the
 * whole column changed: six undifferentiated glyphs per row, none of them
 * labelled, and a scroll and a shield that read as the same rectangle at 16px.
 *
 * Nothing renders when `log_keys` is empty: the API only lists sources that
 * exist on the box, so an empty array means there is genuinely nothing to open.
 */
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
              {/* The raw key: the Logs page owns the friendly labels, and
                  inventing a second name for the same file here would let the
                  two drift. */}
              <span className="font-mono text-xs">{key}</span>
            </Link>
          </DropdownMenuItem>
        ))}
      </DropdownMenuSubContent>
    </DropdownMenuSub>
  );
}
