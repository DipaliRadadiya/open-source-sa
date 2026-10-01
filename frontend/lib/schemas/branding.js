import { z } from "zod";

// `.catch(null)` per field so one bad value does not discard the rest; gaps are filled in get-branding.js.
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
