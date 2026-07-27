"use client";

import { createContext, useContext } from "react";

const AuthContext = createContext(null);

export function AuthProvider({ user, children }) {
  return <AuthContext.Provider value={user}>{children}</AuthContext.Provider>;
}

export function useUser() {
  return useContext(AuthContext);
}
