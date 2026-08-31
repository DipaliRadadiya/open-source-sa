import { z } from "zod";

// Every field is tolerant on purpose: branding is whatever the panel owner
// configured, and one unusable value must never discard the rest of it.
// `.catch(null)` degrades a single bad field instead of failing the object.
// Usability (scheme, emptiness, colour validity) is decided in
// lib/branding/get-branding.js, which fills each gap from the defaults.
const brandingField = z.string().nullish().catch(null);

export const brandingSchema = z
  .object({
    name: brandingField,
    logo: brandingField,
    logo_dark: brandingField,
    icon: brandingField,
    icon_dark: brandingField,
    favicon: brandingField,
    primary_color: brandingField,
  })
  .loose();
