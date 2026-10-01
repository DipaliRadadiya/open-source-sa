// The `!` is required: `Card` hardcodes `shadow-sm` and tailwind-merge does not
// see the conflict. Verify by computed style, not class names.
export const PANEL_CARD = "shadow-e1! ring-foreground/[0.07]";
