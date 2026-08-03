import { LocaleSwitcher } from "@/components/sections/locale-switcher";

export default function AuthLayout({ children }) {
  return (
    <div className="relative flex min-h-svh items-center justify-center overflow-hidden bg-gradient-to-b from-muted/30 via-background to-muted/50 p-4">
      {/* Soft radial glow behind the card for subtle depth */}
      <div
        aria-hidden
        className="pointer-events-none absolute left-1/2 top-1/3 h-[32rem] w-[32rem] -translate-x-1/2 -translate-y-1/2 rounded-full bg-primary/10 blur-3xl"
      />
      <div className="absolute right-4 top-4">
        <LocaleSwitcher />
      </div>
      <div className="relative w-full max-w-sm">{children}</div>
    </div>
  );
}
