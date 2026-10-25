# Install on Plesk (brief notes)

Plesk works much like cPanel. These notes cover the Plesk-specific parts.
For anything not mentioned here, follow [Install on cPanel](install-cpanel.md);
the steps and the order are the same.

1. **Upload**: **Websites & Domains → Files** (File Manager). Upload the
   release `.zip` to the domain's home folder (the one that contains
   `httpdocs`) and **Extract Files**. You get a `gather/` folder next to
   `httpdocs`.
2. **Document root**: **Websites & Domains → (your domain) → Hosting
   Settings** (newer Plesk: **Hosting & DNS → Hosting**) → **Document root**:
   `gather/public`. Plesk lets you change it for every domain, so you never
   need the `.htaccess` fallback here.
3. **PHP**: **PHP** (or **PHP Settings**) for the domain: version **8.2+**,
   **FPM application served by Apache** (or by Nginx), and set
   `upload_max_filesize 320M`, `post_max_size 330M`, `memory_limit 256M`,
   `max_execution_time 300`, `max_input_time 300`. Extensions are managed
   by the server administrator. System health lists anything missing.
4. **Nginx in front of Apache** (the Plesk default) needs nothing extra.
   Apache reads `public/.htaccess`. If you switch the domain to **Nginx only**,
   add the rules from [`nginx.conf.example`](../nginx.conf.example) under
   **Apache & nginx Settings → Additional nginx directives** (only the
   `location` blocks).
5. **Database**: **Databases → Add Database**, with a new user; use host
   `localhost` in the installer.
6. **HTTPS**: **SSL/TLS Certificates → Install** a free Let's Encrypt
   certificate, and tick **Redirect from HTTP to HTTPS**.
7. **Install token**: create `gather/storage/install-token` in File Manager
   with a random string of at least 16 letters and digits. Then open
   `https://<domain>/install`.
8. **Cron**: **Scheduled Tasks → Add Task** → **Run a command**, schedule
   **Cron style** `* * * * *`. Paste the command part of the **Cron line**
   from **Admin → System health** (Plesk's PHP binaries are usually under
   `/opt/plesk/php/8.3/bin/php`).
9. **Email**: create a mailbox under **Mail**, use its SMTP settings under
   **Admin → Email settings**, and see
   [Email deliverability](email-deliverability.md). Plesk's **DNS Settings**
   holds the SPF, DKIM and DMARC records (switch DKIM signing on under
   **Mail Settings**).
10. **Backups**: Gather's own daily backup (**Admin → Backups**) plus Plesk's
    **Backup Manager**, ideally to remote storage.

**Updating**: back up, extract the new `.zip` in the same place (overwrite),
then run `php bin/migrate`. Plesk's **Scheduled Tasks → Run Now** can run
a one-off task if you have no SSH; see
[Running a command without a terminal](install-cpanel.md#running-a-command-without-a-terminal).
