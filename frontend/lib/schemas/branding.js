import { z } from "zod";

// Every field is tolerant: one unusable value must not discard the rest, so
// `.catch(null)` degrades a single bad field. Usability is decided in
// lib/branding/get-branding.js, which fills gaps from the defaults.
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
