"use client";

import { createContext, useContext } from "react";

const BrandingContext = createContext(null);

export function BrandingProvider({ branding, children }) {
  return (
    <BrandingContext.Provider value={branding}>
      {children}
    </BrandingContext.Provider>
  );
}

export function useBranding() {
  const context = useContext(BrandingContext);
  if (!context) {
    throw new Error("useBranding must be used within a BrandingProvider");
  }
  return context;
}
