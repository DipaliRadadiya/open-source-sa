// "close": the panel opened it and no remote user is left.
// "server": the server's own configuration opened it; shown as information only.
export function remoteAccessNote(state) {
  if (!state) return null;
  if (state.can_close) return "close";
  if (state.listening_remotely === true && state.opened_by_panel === false && !state.remote_users) {
    return "server";
  }
  return null;
}
