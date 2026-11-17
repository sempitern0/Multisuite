(() => {
  const { user, destination } = document.body.dataset;
  document.body.removeAttribute('data-user');
  const target = new URL(destination);
  if (target.origin !== location.origin) return;
  const account = JSON.parse(user);
  localStorage.removeItem('kavita-user');
  localStorage.removeItem('kavita-lastlogin');
  if (account) {
    localStorage.setItem('kavita-user', JSON.stringify(account));
    localStorage.setItem('kavita-lastlogin', account.username);
  }
  location.replace(target.href);
})();
