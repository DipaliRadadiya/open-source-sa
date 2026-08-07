"use client";

import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronRight, Plug } from "lucide-react";
import { FormModal } from "@/components/ui/form-modal";
import { useBranding } from "@/components/branding-provider";
import { ProviderLogo } from "@/components/integrations/git/provider-logo";
import { ConnectForm } from "@/components/integrations/git/connect-form";

/**
 * Connecting an account, in two steps.
 *
 * Split rather than one long form because the fields genuinely differ per
 * provider — showing all of them at once would mean explaining which ones to
 * ignore. It also lets the form mount fresh per provider, so the generated Zod
 * schema is fixed for its lifetime instead of swapping under a half-filled
 * form.
 */
export function ConnectDialog({
  providers,
  open,
  showNextStep,
  onFirstAccountConnected,
  onOpenChange,
}) {
  const t = useTranslations("git.connect");
  const { name: brand } = useBranding();
  const [chosen, setChosen] = useState(null);

  function handleOpenChange(next) {
    // Back to step one on close, so reopening never lands mid-flow in a form
    // for a provider the user has forgotten choosing.
    if (!next) setChosen(null);
    onOpenChange?.(next);
  }

  if (chosen) {
    return (
      <ConnectForm
        key={chosen.name}
        provider={chosen}
        open={open}
        showNextStep={showNextStep}
        onFirstAccountConnected={onFirstAccountConnected}
        onBack={() => setChosen(null)}
        onOpenChange={handleOpenChange}
      />
    );
  }

  return (
    <FormModal
      open={open}
      onOpenChange={handleOpenChange}
      icon={Plug}
      title={t("pickTitle")}
      description={t("pickSubtitle")}
    >
      <div className="space-y-2">
        {providers.map((provider) => (
          <button
            key={provider.name}
            type="button"
            onClick={() => setChosen(provider)}
            className="flex w-full items-center gap-3 rounded-lg border px-3 py-3 text-left transition-colors hover:bg-muted/50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-ring"
          >
            <span className="flex size-9 shrink-0 items-center justify-center rounded-md bg-muted">
              <ProviderLogo provider={provider.name} className="size-5" />
            </span>
            <span className="min-w-0 flex-1">
              <span className="block text-sm font-medium">{provider.title}</span>
              {/* A provider the backend adds before we have copy for it still
                  renders — it just gets no one-liner. */}
              {t.has(`hints.${provider.name}`) ? (
                <span className="block text-xs text-muted-foreground">
                  {t(`hints.${provider.name}`)}
                </span>
              ) : null}
            </span>
            <ChevronRight className="size-4 shrink-0 text-muted-foreground" />
          </button>
        ))}
      </div>

      {/* The objection this answers is the one nobody says out loud: what is
          this panel going to do with my credential. */}
      <p className="text-xs leading-relaxed text-muted-foreground">
        {t("readOnly", { brand })}
      </p>
    </FormModal>
  );
}
