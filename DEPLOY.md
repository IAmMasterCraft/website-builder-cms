# Deploying Gather

The deployment guides now live in [`docs/`](docs/README.md):

- [Getting the code](docs/getting-the-code.md): GitHub, release packages, updating
- [Requirements](docs/requirements.md)
- [cPanel shared hosting](docs/install-cpanel.md)
- [VPS (Ubuntu 24.04, Apache or Nginx)](docs/install-vps.md)
- [aaPanel](docs/install-aaPanel.md)
- [Plesk](docs/install-plesk.md)
- [Local development](docs/local-development.md)
- [Email deliverability](docs/email-deliverability.md)

Short version: `bin/check`, commit, `bin/package --zip`, upload, point the
document root at `public/` (or rely on the root `.htaccess` fallback on
Apache/LiteSpeed), switch on HTTPS, create `storage/install-token`, run
`/install`, add the cron line shown on **Admin → System health**, then fix
anything that page marks as a problem.
