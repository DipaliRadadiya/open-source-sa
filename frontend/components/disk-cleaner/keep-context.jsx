"use client";

import { createContext, useContext, useState } from "react";

const KeepContext = createContext(null);

export function KeepProvider({ initial, children }) {
  const value = useState(initial);
  return <KeepContext.Provider value={value}>{children}</KeepContext.Provider>;
}

export function useKeepChoice() {
  return useContext(KeepContext);
}
