"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronDown, ExternalLink, TriangleAlert } from "lucide-react";

import { cn } from "@/lib/utils";
import { Caution } from "@/components/ui/caution";
import { Collapsible, CollapsibleContent, CollapsibleTrigger } from "@/components/ui/collapsible";
import { GoogleDriveRedirectUri } from "@/components/integrations/storage/google-drive-redirect-uri";

/**
 * How to get a Google client ID and secret, said on the screen that asks for
 * them.
 *
 * The form used to present two empty fields and a link to Cloud Console, which
 * is a page of forty products. Everything that then went wrong in real use was
 * a setup step nobody had been told about: the Drive API left disabled (sign-in
 * works, every upload 403s), the consent screen left in "Testing" (backups run
 * all week and stop the next), and the redirect URI never registered. None of
 * those are discoverable by trying — each one surfaces much later, as a failure
 * that says nothing about the step that caused it.
 *
 * So the steps are numbered, each links to the exact Console page rather than
 * its front door, and the two that have cost real time are called out rather
 * than buried in prose.
 *
 * `defaultOpen` is the difference between the two places this appears. Adding a
 * destination, it is open: that reader has not done any of it. Editing one,
 * it is closed — they have a client already and want the Connect button, not a
 * tutorial they have read.
 */
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
            {/* The one that actually happened. Consent succeeds without it,
                because OAuth is a different service, and then the first Drive
                call 403s — so it looks like a credential problem and is not. */}
            <p>{t("step2.body")}</p>
            <ConsoleLink
              href="https://console.cloud.google.com/apis/library/drive.googleapis.com"
              label={t("step2.link")}
            />
          </Step>

          <Step n={3} title={t("step3.title")}>
            <p>{t("step3.body")}</p>
            {/* The single most important line in this guide. An app left in
                "Testing" issues refresh tokens that expire in about a week, so
                the setup works perfectly for a day and dies the next — with
                nothing in the panel having changed. */}
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
            {/* Here rather than anywhere else: this is the field it is pasted
                into, and Google compares it byte for byte. */}
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
      {/* Numbered, because these are strictly ordered — the credential in step
          4 cannot be made before the consent screen in step 3 exists. */}
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

/**
 * A link to the exact page, not to Cloud Console's front door.
 *
 * "Go to Google Cloud Console" lands a first-time reader on a dashboard of
 * forty products with no indication which one this is about.
 */
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
