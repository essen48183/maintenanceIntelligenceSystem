# How to migrate MIS off MAMP onto a host

MIS is intentionally portable — nothing assumes MAMP at runtime. The same
PHP + MySQL deployment that runs locally on MAMP runs on any LAMP-style host.

## What "host" can mean

Any of these work:

- A managed VPS (DigitalOcean, Linode, Hetzner) running a recent Ubuntu / Debian / RHEL.
- A managed PHP host that supports MySQL 8 (Cloudways, Forge, Ploi, Laravel Nightwatch, etc.).
- An on-prem RHEL or Ubuntu box behind your VPN.
- A cloud platform with Apache/Nginx + PHP and a separate managed MySQL service.

What you need:

- **PHP 8.3+** with `pdo_mysql`, `gd`, `curl`, `mbstring` extensions.
- **MySQL 8.0+** (or MariaDB 10.6+ should also work; not officially tested).
- HTTPS (you should not run this app on plain HTTP outside a LAN).

## 1. Bring the code over

Use git:

```bash
git clone https://github.com/<your-org>/maintenanceIntelligenceSystem.git
cd maintenanceIntelligenceSystem
```

## 2. Provision the database

On the host's MySQL:

```sql
CREATE DATABASE mis CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'mis'@'%' IDENTIFIED BY '<strong-password-here>';
GRANT ALL PRIVILEGES ON mis.* TO 'mis'@'%';
FLUSH PRIVILEGES;
```

For test runs you can also create `mis_test`, but don't grant test creds to production.

## 3. Restore your latest local backup (or load schema fresh)

If you've been using the system locally and want to bring the data:

```bash
# On the local Mac:
./db/backup.sh
scp db/backups/mis_<latest>.sql.gz user@host:/tmp/

# On the host:
cd /var/www/mis
MIS_DB_HOST=127.0.0.1 MIS_DB_PORT=3306 \
MIS_DB_USER=mis        MIS_DB_PASS='<strong-password>' \
MIS_DB_SOCKET=         \
./db/restore.sh /tmp/mis_<latest>.sql.gz
```

Or, for a fresh production install, load schema (and optionally the seed):

```bash
mysql -h 127.0.0.1 -u mis -p mis < db/schema.sql
mysql -h 127.0.0.1 -u mis -p mis < db/seed.sql   # optional — only if you want demo data
```

## 4. Configure

```bash
cp config/config.example.php config/config.php
```

Edit `config/config.php`:

```php
'db' => [
    'host'     => '127.0.0.1',          // or your managed-MySQL host
    'port'     => 3306,
    'socket'   => null,                 // important — clears the MAMP socket
    'name'     => 'mis',
    'user'     => 'mis',
    'password' => '<strong-password>',
    'charset'  => 'utf8mb4',
],
'auth' => [
    'session_name'     => 'mis_session',
    'session_lifetime' => 8 * 3600,
    'cookie_secure'    => true,         // ON, you're on HTTPS
    'cookie_samesite'  => 'Lax',
],
'app' => [
    'env'      => 'production',
    'base_url' => 'https://mis.example.com',
],
'anthropic' => [
    'api_key' => '<production key>',
    'model'   => 'claude-opus-4-7',
    'max_tokens' => 1024,
],
```

`config.php` is gitignored. Never check it into the repo.

## 5. Web server

Point the document root at `<project>/public/` so URLs map correctly. Two examples:

### Apache

```apache
<VirtualHost *:443>
  ServerName mis.example.com
  DocumentRoot /var/www/mis/public

  <Directory /var/www/mis/public>
    AllowOverride All
    Require all granted
  </Directory>

  # TLS
  SSLEngine on
  SSLCertificateFile      /etc/letsencrypt/live/mis.example.com/fullchain.pem
  SSLCertificateKeyFile   /etc/letsencrypt/live/mis.example.com/privkey.pem
</VirtualHost>
```

### Nginx + php-fpm

```nginx
server {
  listen 443 ssl http2;
  server_name mis.example.com;
  root /var/www/mis/public;
  index index.php;

  ssl_certificate     /etc/letsencrypt/live/mis.example.com/fullchain.pem;
  ssl_certificate_key /etc/letsencrypt/live/mis.example.com/privkey.pem;

  location / { try_files $uri $uri/ /index.php?$query_string; }

  location ~ \.php$ {
    fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
  }

  # Disable PHP execution in writable areas (defence in depth)
  location ~ ^/(api/|assets/|icons/) { try_files $uri =404; }
}
```

## 6. File permissions

```bash
sudo chown -R www-data:www-data /var/www/mis
sudo chmod -R 750 /var/www/mis
# config/config.php should not be world-readable
sudo chmod 640 /var/www/mis/config/config.php
```

`docs/library/` (the PDF library) needs to be readable by the web server but **never writable from the web** — the upload path will land files there server-side once that feature is built.

## 7. PWA / HTTPS

- The service worker (`/service-worker.js`) requires HTTPS in production. Browsers refuse to register service workers over HTTP except on `localhost`.
- The PWA install prompt appears once the manifest + service worker are reachable and the page is over HTTPS.

## 8. Backups (production)

```cron
# /etc/cron.d/mis-backup
0 3 * * * www-data  cd /var/www/mis && ./db/backup.sh --keep 60 >> /var/log/mis-backup.log 2>&1
```

Rotate dumps off the host into S3 / Backblaze / your central backup system. Test a restore at least once a quarter.

## 9. Smoke checks after deploy

```bash
# Page loads
curl -s -o /dev/null -w "%{http_code}\n" https://mis.example.com/
# Auth API responds JSON
curl -s https://mis.example.com/api/auth.php?action=me | head -c 200
# Tests pass against the test DB (if you've populated mis_test)
php tests/run.php
```

## 10. Going live with users

1. Sign in as `admin` once and immediately reset the seed admin password from **Manage Users**.
2. Either deactivate or delete-and-recreate the seed accounts you don't need (`viewer`, `rsmith`, `qchen`, the demo techs).
3. Use **Manage Users** to create real employee accounts; grant `rts_authority` only to senior techs, `inspection_authority` only to supervisors / quality.
4. Set a real Anthropic API key in `config.php` and reload PHP-fpm/Apache.
5. Verify a live AI call by signing in as a maintenance tech and asking the assistant a question on any open fault.
