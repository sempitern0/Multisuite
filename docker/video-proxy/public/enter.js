// Runs on the Vídeo gateway's origin (page from the portal, routes/api.php). Stores the session
// the portal opened for this person where Jellyfin's web interface keeps its own login
// (localStorage «jellyfin_credentials»), replacing any previous entry for this server, then opens
// Jellyfin. Without a session it only clears nothing and lets Jellyfin show its sign-in page.
(() => {
  const raw = document.body.dataset.session;
  document.body.removeAttribute('data-session');
  const session = raw ? JSON.parse(raw) : null;
  if (session && session.server && session.user && session.token) {
    let credentials = { Servers: [] };
    try {
      const stored = JSON.parse(localStorage.getItem('jellyfin_credentials') || 'null');
      if (stored && Array.isArray(stored.Servers)) credentials = stored;
    } catch (_) { /* unreadable: start again */ }
    credentials.Servers = credentials.Servers.filter(server => server && server.Id !== session.server);
    credentials.Servers.unshift({
      Id: session.server,
      Name: session.name || 'Vídeo',
      ManualAddress: location.origin,
      manualAddressOnly: true,
      LastConnectionMode: 2,
      UserId: session.user,
      AccessToken: session.token,
      DateLastAccessed: Date.now(),
    });
    localStorage.setItem('jellyfin_credentials', JSON.stringify(credentials));
  }
  location.replace('/web/');
})();
