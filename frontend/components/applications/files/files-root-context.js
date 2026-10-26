"use client";

import { createContext, useContext } from "react";
import { serverPath } from "@/lib/files/server-path";

// The File Manager's folder on the server, so Copy path gives a path that works in a shell.
const FilesRootContext = createContext(null);

export const FilesRootProvider = FilesRootContext.Provider;

export function useServerPath() {
  const root = useContext(FilesRootContext);
  return { root, toServerPath: (path) => serverPath(root, path) };
}
