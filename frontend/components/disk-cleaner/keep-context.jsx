"use client";

import { createContext, useContext, useState } from "react";

// The Clean panel's keep choice, shared with the "Can be freed" tile so both price the same choice.
const KeepContext = createContext(null);

export function KeepProvider({ initial, children }) {
  const value = useState(initial);
  return <KeepContext.Provider value={value}>{children}</KeepContext.Provider>;
}

export function useKeepChoice() {
  return useContext(KeepContext);
}
