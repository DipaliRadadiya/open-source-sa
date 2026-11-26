"use client";

import { createContext, useContext } from "react";
import { serverPath } from "@/lib/files/server-path";

const FilesRootContext = createContext(null);

export const FilesRootProvider = FilesRootContext.Provider;

export function useServerPath() {
  const root = useContext(FilesRootContext);
  return { root, toServerPath: (path) => serverPath(root, path) };
}
