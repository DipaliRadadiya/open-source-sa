import Link from "@/components/ui/app-link";
import { useRef, useState } from "react";
import { MoreHorizontal, Pencil, Copy, Trash2, ScrollText, SquareArrowOutUpRight } from "lucide-react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { MenuItemHint } from "@/components/data-table/menu-item-hint";
import { EditCronjobDialog } from "@/components/cron-jobs/edit-cronjob-dialog";
import { DeleteCronjobDialog } from "@/components/cron-jobs/delete-cronjob-dialog";

export function CronjobRowActions({
  job,
  schedulePresets,
  commandPresets,
  applications = [],
  placeholder,
  timezone,
  onDuplicate,
  runAs,
  prevPage = null,
  canManage = true,
  canViewLogs = true,
}) {
  const t = useTranslations("cronJobs");
  const [editOpen, setEditOpen] = useState(false);
  const [delOpen, setDelOpen] = useState(false);
  // Only an item that opens a dialog keeps focus off the ⋯ button (the dialog
  // takes it, then hands it back); Escape or a click away returns it there.
  const openingDialog = useRef(false);

  return (
    <div className="text-right">
      <DropdownMenu>
        <DropdownMenuTrigger asChild>
          <Button variant="ghost" size="icon" className="size-8">
            <MoreHorizontal className="size-4" />
            <span className="sr-only">{t("actions.label")}</span>
          </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent
          align="end"
          className="w-64"
          onCloseAutoFocus={(e) => {
            if (!openingDialog.current) return;
            openingDialog.current = false;
            e.preventDefault();
          }}
        >
          {/* Cron keeps no "last run" record; captured output is the only answer to "did it work?". */}
          {!canViewLogs ? (
            <MenuItemHint hint={t("actions.noLogsPermission")}>
              <DropdownMenuItem disabled>
                <ScrollText className="size-4" />
                {t("actions.viewOutput")}
              </DropdownMenuItem>
            </MenuItemHint>
          ) : job.log_key ? (
            <DropdownMenuItem asChild>
              {/* New tab: this leaves Cron Jobs for the server-wide Logs page. */}
              <Link
                href={`/logs?source=${encodeURIComponent(job.log_key)}`}
                target="_blank"
                rel="noreferrer"
              >
                <ScrollText className="size-4" />
                {t("actions.viewOutput")}
                <SquareArrowOutUpRight className="ml-auto size-3.5 text-muted-foreground" />
              </Link>
            </DropdownMenuItem>
          ) : (
            <DropdownMenuItem disabled>
              <ScrollText className="size-4" />
              {t("actions.noOutput")}
            </DropdownMenuItem>
          )}
          <DropdownMenuSeparator />
          <MenuItemHint hint={canManage ? null : t("noPermission")}>
            <DropdownMenuItem disabled={!canManage} onSelect={() => { openingDialog.current = true; setEditOpen(true); }}>
              <Pencil className="size-4" />
              {t("actions.edit")}
            </DropdownMenuItem>
          </MenuItemHint>
          <MenuItemHint hint={canManage ? null : t("noPermission")}>
            <DropdownMenuItem disabled={!canManage} onSelect={() => { openingDialog.current = true; onDuplicate?.(job); }}>
              <Copy className="size-4" />
              {t("actions.duplicate")}
            </DropdownMenuItem>
          </MenuItemHint>
          <DropdownMenuSeparator />
          <MenuItemHint hint={canManage ? null : t("noPermission")}>
            <DropdownMenuItem variant="destructive" disabled={!canManage} onSelect={() => { openingDialog.current = true; setDelOpen(true); }}>
              <Trash2 className="size-4" />
              {t("actions.delete")}
            </DropdownMenuItem>
          </MenuItemHint>
        </DropdownMenuContent>
      </DropdownMenu>

      {editOpen ? (
        <EditCronjobDialog
          job={job}
          open={editOpen}
          onOpenChange={setEditOpen}
          schedulePresets={schedulePresets}
          commandPresets={commandPresets}
          applications={applications}
          placeholder={placeholder}
          timezone={timezone}
          systemUsers={runAs?.users}
          systemUsersFailed={runAs?.failed}
        />
      ) : null}
      <DeleteCronjobDialog job={job} open={delOpen} onOpenChange={setDelOpen} prevPage={prevPage} />
    </div>
  );
}
