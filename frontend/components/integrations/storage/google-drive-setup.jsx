"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, ExternalLink, TriangleAlert } from "lucide-react";

import { cn } from "@/lib/utils";
import { Caution } from "@/components/ui/caution";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { GoogleDriveRedirectUri } from "@/components/integrations/storage/google-drive-redirect-uri";

// `defaultOpen`: open when adding a destination, closed when editing.
export function GoogleDriveSetup({ redirectUri, defaultOpen = false }) {
  const t = useTranslations("storage.oauth.setup");
  const [open, setOpen] = useState(defaultOpen);

  return (
    <Collapsible open={open} onOpenChange={setOpen} className="rounded-lg border">
      <CollapsibleTrigger className="flex w-full items-center justify-between gap-2 p-3 text-left">
        <span className="text-sm font-medium">{t("title")}</span>
        <ChevronDown
          className={cn(
            "size-4 shrink-0 text-muted-foreground transition-transform",
            open && "rotate-180",
          )}
        />
      </CollapsibleTrigger>

      <CollapsibleContent className="space-y-3 px-3 pb-3">
        <p className="text-xs leading-5 text-muted-foreground">{t("intro")}</p>

        <ol className="space-y-3">
          <Step n={1} title={t("step1.title")}>
            <p>{t("step1.body")}</p>
            <ConsoleLink href="https://console.cloud.google.com/projectcreate" label={t("step1.link")} />
          </Step>

          <Step n={2} title={t("step2.title")}>
            {/* Without the Drive API, consent succeeds but the first Drive call
                403s, which looks like a credential problem. */}
            <p>{t("step2.body")}</p>
            <ConsoleLink
              href="https://console.cloud.google.com/apis/library/drive.googleapis.com"
              label={t("step2.link")}
            />
          </Step>

          <Step n={3} title={t("step3.title")}>
            <p>{t("step3.body")}</p>
            {/* An app left in "Testing" issues refresh tokens that expire in
                about a week, so backups stop with nothing changed in the panel. */}
            <Caution className="mt-2">
              <p className="text-xs leading-5">{t("step3.publish")}</p>
            </Caution>
            <ConsoleLink
              href="https://console.cloud.google.com/apis/credentials/consent"
              label={t("step3.link")}
            />
          </Step>

          <Step n={4} title={t("step4.title")}>
            <p>{t("step4.body")}</p>
            {/* Shown at the step where it is pasted; Google compares it byte for byte. */}
            <GoogleDriveRedirectUri uri={redirectUri} className="mt-2" />
            <ConsoleLink
              href="https://console.cloud.google.com/apis/credentials"
              label={t("step4.link")}
            />
          </Step>

          <Step n={5} title={t("step5.title")}>
            <p>{t("step5.body")}</p>
          </Step>
        </ol>

        <p className="flex items-start gap-1.5 text-xs leading-5 text-muted-foreground">
          <TriangleAlert className="mt-0.5 size-3.5 shrink-0" />
          {t("scope")}
        </p>
      </CollapsibleContent>
    </Collapsible>
  );
}

function Step({ n, title, children }) {
  return (
    <li className="flex gap-2.5">
      {/* Numbered because the steps are strictly ordered. */}
      <span className="mt-0.5 flex size-5 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-medium tabular-nums">
        {n}
      </span>
      <div className="min-w-0 flex-1 space-y-1 text-xs leading-5">
        <p className="text-sm font-medium leading-5">{title}</p>
        {children}
      </div>
    </li>
  );
}

function ConsoleLink({ href, label }) {
  return (
    <a
      href={href}
      target="_blank"
      rel="noreferrer noopener"
      className="inline-flex items-center gap-1 text-xs font-medium text-primary underline-offset-4 hover:underline"
    >
      {label}
      <ExternalLink className="size-3" />
    </a>
  );
}
