// Reusable empty-state card for list pages. `icon` is a Lucide component;
// `action` is an optional node (e.g. a CTA button).
export function EmptyState({ icon: Icon, title, description, action }) {
  return (
    // px-6: with padding on the vertical axis only, the description ran to
    // within a pixel of the dashed border on a phone and read as broken rather
    // than centred. py stays larger than px — an empty state is mostly air.
    <div className="flex flex-col items-center justify-center gap-3 rounded-xl border border-dashed px-6 py-16 text-center">
      <span className="flex size-12 items-center justify-center rounded-full bg-muted text-muted-foreground">
        <Icon className="size-5" />
      </span>
      <div className="space-y-1">
        <p className="font-medium">{title}</p>
        <p className="max-w-sm text-sm text-pretty text-muted-foreground">{description}</p>
      </div>
      {action}
    </div>
  );
}
