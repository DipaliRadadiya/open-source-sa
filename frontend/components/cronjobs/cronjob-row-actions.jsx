"use client";

import Link from "next/link";
import { useState } from "react";
import { MoreHorizontal, Pencil, Copy, Trash2, ScrollText } from "lucide-react";
import { useTranslations } from "next-intl";
import { Button } from "@/components/ui/button";
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuSeparator,
  DropdownMenuTrigger,
} from "@/components/ui/dropdown-menu";
import { EditCronjobDialog } from "@/components/cronjobs/edit-cronjob-dialog";
import { DeleteCronjobDialog } from "@/components/cronjobs/delete-cronjob-dialog";

export function CronjobRowActions({
  job,
  schedulePresets,
  commandPresets,
  placeholder,
  timezone,
  onDuplicate,
}) {
  const t = useTranslations("cronJobs");
  const [editOpen, setEditOpen] = useState(false);
  const [delOpen, setDelOpen] = useState(false);

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
          className="w-40"
          onCloseAutoFocus={(e) => e.preventDefault()}
        >
          {/* What the job actually did, first. There's deliberately no
              "last run" field — cron keeps no such record — so the captured
              output, with its `exit=` status line, is the only honest answer to
              "did it work?". Null until the job is next saved with capture on,
              so the item says so rather than opening an empty viewer. */}
          {job.log_key ? (
            <DropdownMenuItem asChild>
              <Link href={`/logs?source=${encodeURIComponent(job.log_key)}`}>
                <ScrollText className="size-4" />
                {t("actions.viewOutput")}
              </Link>
            </DropdownMenuItem>
          ) : (
            <DropdownMenuItem disabled>
              <ScrollText className="size-4" />
              {t("actions.noOutput")}
            </DropdownMenuItem>
          )}
          <DropdownMenuSeparator />
          <DropdownMenuItem onSelect={() => setEditOpen(true)}>
            <Pencil className="size-4" />
            {t("actions.edit")}
          </DropdownMenuItem>
          <DropdownMenuItem onSelect={() => onDuplicate?.(job)}>
            <Copy className="size-4" />
            {t("actions.duplicate")}
          </DropdownMenuItem>
          <DropdownMenuSeparator />
          <DropdownMenuItem variant="destructive" onSelect={() => setDelOpen(true)}>
            <Trash2 className="size-4" />
            {t("actions.delete")}
          </DropdownMenuItem>
        </DropdownMenuContent>
      </DropdownMenu>

      {editOpen ? (
        <EditCronjobDialog
          job={job}
          open={editOpen}
          onOpenChange={setEditOpen}
          schedulePresets={schedulePresets}
          commandPresets={commandPresets}
          placeholder={placeholder}
          timezone={timezone}
        />
      ) : null}
      <DeleteCronjobDialog job={job} open={delOpen} onOpenChange={setDelOpen} />
    </div>
  );
}
