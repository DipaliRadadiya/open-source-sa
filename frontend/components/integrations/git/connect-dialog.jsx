import { useState } from "react";
import { useTranslations } from "next-intl";
import { ChevronRight, Plug } from "lucide-react";
import { FormModal } from "@/components/ui/form-modal";
import { useBranding } from "@/components/branding-provider";
import { ProviderLogo } from "@/components/integrations/git/provider-logo";
import { ConnectForm } from "@/components/integrations/git/connect-form";

// Each step is its own modal, so the control that opened the current one (a
// provider button, or "Change") is gone by the time it closes.
function focusConnectButton(event) {
  const opener = document.querySelector("[data-git-connect]");
  if (!opener) return;
  event.preventDefault();
  opener.focus();
}

// The form mounts fresh per provider so its generated Zod schema never changes under a half-filled form.
export function ConnectDialog({
  providers,
  open,
  onAccountConnected,
  onOpenChange,
}) {
  const t = useTranslations("git.connect");
  const { name: brand } = useBranding();
  const [chosen, setChosen] = useState(null);

  function handleOpenChange(next) {
    // Reset to step one on close.
    if (!next) setChosen(null);
    onOpenChange?.(next);
  }

  if (chosen) {
    return (
      <ConnectForm
        key={chosen.name}
        provider={chosen}
        open={open}
        onAccountConnected={onAccountConnected}
        onBack={() => setChosen(null)}
        onOpenChange={handleOpenChange}
        onCloseAutoFocus={focusConnectButton}
      />
    );
  }

  return (
    <FormModal
      open={open}
      onOpenChange={handleOpenChange}
      onCloseAutoFocus={focusConnectButton}
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
              {/* Providers without a translated hint still render, without one. */}
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

      <p className="text-xs leading-relaxed text-muted-foreground">
        {t("readOnly", { brand })}
      </p>
    </FormModal>
  );
}
