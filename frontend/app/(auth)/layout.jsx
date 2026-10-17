import { LocaleSwitcher } from "@/components/sections/locale-switcher";
import { TooltipProvider } from "@/components/ui/tooltip";
import { ErrorCopy } from "@/components/sections/error-copy";

export default function AuthLayout({ children }) {
  return (
    <TooltipProvider delayDuration={300}>
      <ErrorCopy />
      {/* Steel UI (Krishna, 14 Oct): the dashboard banner's look — flat, a faint grid
          across the page, green corner brackets on the card — instead of a soft glow.
          Half the banner's grid: across a whole empty page it read too strong (17 Oct). */}
      <div className="relative flex min-h-svh items-center justify-center overflow-hidden bg-background p-4 [--hero-grid:rgb(15_23_42/0.018)] dark:[--hero-grid:rgb(255_255_255/0.016)]">
        <div aria-hidden className="bg-hero-grid pointer-events-none absolute inset-0" />
        <div className="absolute right-4 top-4">
          <LocaleSwitcher />
        </div>
        <div className="relative w-full max-w-sm">
          <span aria-hidden className="pointer-events-none absolute -top-2 -left-2 size-4 border-t-2 border-l-2 border-primary" />
          <span aria-hidden className="pointer-events-none absolute -right-2 -bottom-2 size-4 border-r-2 border-b-2 border-primary" />
          {children}
        </div>
      </div>
    </TooltipProvider>
  );
}
