"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { Pencil } from "lucide-react";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { CopyButton } from "@/components/ui/copy-button";
import { Button } from "@/components/ui/button";
import { WebRootDialog } from "@/components/applications/web-root-dialog";

/**
 * What this site is and where it lives. Every row is a fact the user might have
 * to quote to someone — so the paths and versions are monospaced, and a fact
 * that does not apply to this site type is left out rather than shown as "—".
 */
export function SiteFactsCard({ application, canManage = false }) {
  const t = useTranslations("applications");
  const [editingWebRoot, setEditingWebRoot] = useState(false);

  const facts = [
    { label: t("columns.type"), value: application.site_type_title ?? application.site_type },
    { label: t("columns.owner"), value: application.system_user?.username, mono: true },
    // The paths and the port are the values people paste into configs, so they
    // carry a copy control; the rest are just read.
    {
      label: t("facts.webRoot"),
      value: application.web_root,
      mono: true,
      copy: true,
      // The one fact on this card that is a setting rather than a record.
      onEdit: canManage ? () => setEditingWebRoot(true) : null,
    },
    { label: t("facts.php"), value: application.php_version, mono: true },
    { label: t("facts.node"), value: application.node_version, mono: true },
    { label: t("facts.port"), value: application.app_port, mono: true, copy: true },
    { label: t("columns.created"), value: application.created_at_human },
  ].filter((fact) => fact.value !== null && fact.value !== undefined && fact.value !== "");

  return (
    <Card>
      <CardHeader>
        <CardTitle>{t("facts.title")}</CardTitle>
      </CardHeader>
      <CardContent className="grid gap-4 text-sm sm:grid-cols-2">
        {facts.map((fact) => (
          <div key={fact.label} className="space-y-1">
            <p className="text-xs text-muted-foreground">{fact.label}</p>
            <div className="flex items-center gap-1">
              <p className={fact.mono ? "break-all font-mono text-xs" : "font-medium"}>
                {fact.value}
              </p>
              {fact.copy ? <CopyButton value={String(fact.value)} /> : null}
              {fact.onEdit ? (
                <Button
                  type="button"
                  variant="ghost"
                  size="icon-sm"
                  onClick={fact.onEdit}
                  aria-label={t("webRoot.title")}
                >
                  <Pencil className="size-3.5" />
                </Button>
              ) : null}
            </div>
          </div>
        ))}
      </CardContent>

      <WebRootDialog
        application={application}
        open={editingWebRoot}
        onOpenChange={setEditingWebRoot}
      />
    </Card>
  );
}
