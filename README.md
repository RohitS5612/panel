# Service Panel (Apache + PHP + Node.js fallback)

A dashboard to manage `systemd` services from the browser. This repo now includes:

- `index.php` (Apache/PHP version)
- `node-dashboard.js` (Node.js fallback version)

Both versions include (with matching UI/layout):

- Login gate (`admin` / `db2026` for now)
- Service list (`systemctl list-units --type=service --all`)
- Actions: `start`, `stop`, `restart`, `reload`
- Overview cards: total / active / inactive / failed / other

## Apache domain routing (`panel.devmojangirc.qzz.io`)

Create `/etc/apache2/sites-available/panel.devmojangirc.qzz.io.conf`:

```apache
<VirtualHost *:80>
    ServerName panel.devmojangirc.qzz.io
    DocumentRoot /var/www/panel.devmojangirc.qzz.io

    <Directory /var/www/panel.devmojangirc.qzz.io>
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/panel_error.log
    CustomLog ${APACHE_LOG_DIR}/panel_access.log combined
</VirtualHost>
```

Enable:

```bash
sudo a2ensite panel.devmojangirc.qzz.io.conf
sudo a2dissite 000-default.conf
sudo systemctl reload apache2
```

## Deploy this repo to that domain folder

```bash
sudo mkdir -p /var/www/panel.devmojangirc.qzz.io
sudo cp -r ./* /var/www/panel.devmojangirc.qzz.io/
sudo chown -R www-data:www-data /var/www/panel.devmojangirc.qzz.io
```

## Option A: PHP version (default via Apache)

Install dependencies:

```bash
sudo apt update
sudo apt install -y apache2 php libapache2-mod-php
sudo systemctl restart apache2
```

Apache will serve `index.php` from the domain directory.

## Option B: Node.js fallback version

Run manually:

```bash
node node-dashboard.js
```

To expose through Apache, enable reverse proxy modules and proxy to port `3000`:

```bash
sudo a2enmod proxy proxy_http
```

Example vhost proxy block:

```apache
ProxyPreserveHost On
ProxyPass / http://127.0.0.1:3000/
ProxyPassReverse / http://127.0.0.1:3000/
```

## Allow service control (`systemctl`)

Both apps default to using `sudo /bin/systemctl ...`. Add a limited sudoers rule:

```bash
sudo visudo -f /etc/sudoers.d/panel-systemctl
```

```sudoers
www-data ALL=(root) NOPASSWD: /bin/systemctl start *, /bin/systemctl stop *, /bin/systemctl restart *, /bin/systemctl reload *, /bin/systemctl list-units *
```

If running Node under another user, replace `www-data` with that user.

## Getting the latest version to GitHub (exact commands)

If your GitHub repo does not show the latest files yet, run this from this repo:

```bash
cd /workspace/panel
./scripts/push-to-github.sh https://github.com/<YOUR_USER>/<YOUR_REPO>.git work
```

If `origin` already exists, the script verifies it matches the URL you passed and then pushes the branch.

You can also push manually:

```bash
cd /workspace/panel
git remote add origin https://github.com/<YOUR_USER>/<YOUR_REPO>.git
git push -u origin work
```

## Ubuntu 24.04 note

This setup is intended for Ubuntu 24.04 with systemd. If you run inside a container that is not booted with systemd as PID 1, service lists may be empty and actions may fail.

## Important

- Change the hardcoded password before production use.
- Put HTTPS in front of this panel.
- Consider replacing wildcard sudoers with a strict allowlist wrapper script.
