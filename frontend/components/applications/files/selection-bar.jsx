import { useTranslations } from "next-intl";
import { Copy, FileArchive, FolderInput, Lock, Trash2, X } from "lucide-react";
import { Button } from "@/components/ui/button";
import { ReasonTooltip } from "@/components/ui/reason-tooltip";
import { BULK_PATH_LIMIT } from "@/lib/api/files";
import { dirname } from "@/lib/files/path-helpers";

// Inapplicable actions are disabled with a reason, not hidden. Rename and Edit are
// absent: they must not silently apply to the first selected file.
export function SelectionBar({ selected, onClear, onAction, canManage }) {
  const t = useTranslations("applications.files");
  if (selected.length === 0) return null;

  const overLimit = selected.length > BULK_PATH_LIMIT;
  // `zip` runs from the sources' folder, so a selection spanning folders cannot be
  // compressed; checked here so the button explains before the server returns 422.
  const spansFolders = new Set(selected.map((path) => dirname(path))).size > 1;

  const blocked = !canManage
    ? t("noPermission")
    : overLimit
      ? t("bulk.tooMany", { limit: BULK_PATH_LIMIT, count: selected.length })
      : null;

  const actions = [
    { key: "move", icon: FolderInput, reason: blocked },
    { key: "copy", icon: Copy, reason: blocked },
    {
      key: "compress",
      icon: FileArchive,
      reason: blocked ?? (spansFolders ? t("bulk.oneFolderOnly") : null),
    },
    { key: "permissions", icon: Lock, reason: blocked },
  ];

  return (
    <div className="flex flex-wrap items-center gap-2 rounded-xl border bg-muted/40 px-3 py-2">
      <span className="text-sm font-medium tabular-nums">
        {t("bulk.selected", { count: selected.length })}
      </span>
      <Button
        type="button"
        variant="ghost"
        size="sm"
        className="h-7 px-2 text-xs"
        onClick={onClear}
      >
        <X className="size-3.5" />
        {t("bulk.clear")}
      </Button>

      <div className="ms-auto flex flex-wrap items-center gap-2">
        {actions.map(({ key, icon: Icon, reason }) => (
          <ReasonTooltip key={key} reason={reason}>
            <Button
              type="button"
              variant="outline"
              size="sm"
              disabled={Boolean(reason)}
              onClick={() => onAction(key)}
            >
              <Icon className="size-3.5" />
              {t(`bulk.${key}`)}
            </Button>
          </ReasonTooltip>
        ))}
        <ReasonTooltip reason={blocked}>
          <Button
            type="button"
            variant="destructive"
            size="sm"
            disabled={Boolean(blocked)}
            onClick={() => onAction("delete")}
          >
            <Trash2 className="size-3.5" />
            {t("bulk.delete")}
          </Button>
        </ReasonTooltip>
      </div>
    </div>
  );
}
