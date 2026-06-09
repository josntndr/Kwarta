# Deployment Notes

The web server document root should point to:

```text
frontend/public
```

Keep `backend` and `database` outside the public web root. The browser should only be able to access files inside `frontend/public`.

## Local PHP Server

From the project root:

```bash
php -S localhost:8000 -t frontend/public
```

## Docker

If PHP and MySQL are not installed locally, run from the project root:

```bash
docker compose up --build
```

Then open `http://localhost:8000`.

The MySQL container exposes port `3307` on the host. The app reads database settings from environment variables in [../docker-compose.yml](../docker-compose.yml).

## Apache

Use [apache-vhost.conf](apache-vhost.conf) as a starting point. Replace `C:/path/to/Kwarta` with your real project path.

## Nginx

Use [nginx.conf](nginx.conf) as a starting point. Replace `/path/to/Kwarta` with your real project path and confirm your PHP-FPM socket or host/port.

## Vercel

This project includes [../vercel.json](../vercel.json) for a Vercel PHP deployment using a PHP runtime. Configure these environment variables in Vercel:

Use this project name in Vercel:

```text
kwarta-financial-tracker
```

If the name is available, the public URL will be:

```text
https://kwarta-financial-tracker.vercel.app
```

```env
DB_HOST=
DB_PORT=3306
DB_NAME=
DB_USER=
DB_PASSWORD=
DATABASE_URL=
MYSQL_URL=
APP_SECRET=
APP_URL=
APP_ENV=production
```

Vercel does not provide MySQL. Use a hosted MySQL-compatible database, import [../database/kwarta.sql](../database/kwarta.sql), then run the migration files in [../database/migrations](../database/migrations) when upgrading an existing database.

If the deployed site opens but login/register show a database warning, the Vercel link is live but the production database environment variables are missing or incorrect.

### Required Vercel Environment Variables

Add these in **Vercel Dashboard > kwarta-financial-tracker > Settings > Environment Variables** for the **Production** environment:

```env
DB_HOST=your-online-mysql-host
DB_PORT=3306
DB_NAME=your-database-name
DB_USER=your-database-user
DB_PASSWORD=your-database-password
APP_SECRET=long-random-session-secret
APP_URL=https://kwarta-financial-tracker.vercel.app
APP_ENV=production
```

If your database provider gives a single URL, you may use this instead of the separate DB fields:

```env
DATABASE_URL=mysql://user:password@host:3306/database_name
```

Redeploy after changing environment variables. Without a real hosted MySQL database, registration and login cannot work on Vercel even if the PHP deployment is successful.

### Vercel 404: DEPLOYMENT_NOT_FOUND

If `https://kwarta-financial-tracker.vercel.app` shows `404: NOT_FOUND` with code `DEPLOYMENT_NOT_FOUND`, Vercel has not created or assigned a successful deployment to that exact URL yet. This is not a PHP or database error.

Fix checklist:

1. Open your Vercel dashboard.
2. Open the Kwarta project.
3. Go to **Settings > General** and set the project name to `kwarta-financial-tracker`.
4. Go to **Deployments** and confirm there is a successful production deployment.
5. If there is no successful deployment, click the failed deployment and read the build logs.
6. If the project name was changed after deploying, redeploy the project.
7. Use the exact **Visit** link Vercel shows after the successful production deployment.

From the CLI:

```bash
vercel login
vercel link
vercel --prod
```

When `vercel link` asks for the project name, use:

```text
kwarta-financial-tracker
```

Admin accounts are not created through the UI. Register your owner account normally, then promote it manually:

```sql
UPDATE users
SET role = 'admin'
WHERE email = 'your-email@gmail.com';
```

After deployment, test:

- `/` landing page
- `/login` and `/register`
- `/dashboard` as a normal user
- `/admin/dashboard` as an admin
- `/admin/dashboard` as a normal user, which must redirect away
- `/logout`
