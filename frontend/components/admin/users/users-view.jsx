"use client";

import { createContext, useContext, useState } from "react";
import { NavTransitionProvider } from "@/components/data-table/nav-transition";
import { UserFormDialog } from "@/components/admin/users/user-form-dialog";

const CreateUserContext = createContext(null);

export function useCreateUser() {
  return useContext(CreateUserContext);
}

// Hosts the create-user dialog so both the toolbar and the empty state can open it.
export function UsersView({ roles, rolesFailed = false, children }) {
  const [createOpen, setCreateOpen] = useState(false);

  return (
    <NavTransitionProvider>
      <CreateUserContext.Provider value={{ openCreate: () => setCreateOpen(true) }}>
        {children}
        <UserFormDialog
          mode="create"
          roles={roles}
          rolesFailed={rolesFailed}
          open={createOpen}
          onOpenChange={setCreateOpen}
        />
      </CreateUserContext.Provider>
    </NavTransitionProvider>
  );
}
