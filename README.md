# Academic Commission

PHP + MySQL marketplace for academic commissions (clients post requests, commissioners send offers, escrow, chat, disputes).

## Folder tree

```
acw/
├── public/                 <- web root (the only folder the browser should reach)
│   ├── index.php
│   ├── login.php  register.php  logout.php
│   ├── dashboard.php  profile.php  inbox.php  transactions.php
│   ├── forum.php  post.php  create_post.php  vote.php  offer.php
│   ├── orders.php  order.php  order_action.php  deliver.php  preview.php  download.php
│   ├── messages.php  chat.php
│   ├── admin/              index.php  order.php  action.php
│   └── assets/
│       ├── css/            style.css  chat.css  dash.css  forum.css  order.css  profile.css
│       └── js/             chat.js  countdown.js
├── src/                    <- shared PHP logic (never served directly)
│   ├── bootstrap.php       loads everything below, starts the session, defines BASE_URL
│   ├── config.php          DB credentials, fees, windows, paths
│   ├── database.php        PDO connection
│   ├── cache.php           APCu / file cache
│   ├── helpers.php         e(), money(), csrf, redirect(), vote_box(), status_tag() ...
│   ├── auth.php            require_login(), require_admin(), notify()
│   ├── reputation.php      stats, tiers, trust score, badges, ratings
│   ├── order_lib.php       lazy expiry, escrow release/refund
│   └── chat_lib.php        chat queries and the chat panel
├── templates/              head.php  nav.php  auth_side.php
├── database/migrations/    001_users.sql ... 009_indexes.sql (run in order)
├── storage/                <- private runtime data, blocked by .htaccess
│   ├── outputs/            delivered files and previews
│   └── cache/              file cache
├── tools/                  seed.php  monitor.py
└── docs/                   TEST_PLAN.md
```

## Setup

1. Run the migrations in order:
   `for f in database/migrations/*.sql; do mysql -u root < "$f"; done`
2. Edit `src/config.php` if your DB credentials differ.
3. Put the project in `htdocs/acw` (XAMPP). Open `http://localhost/acw/public/`.
   For a cleaner URL, point the Apache DocumentRoot or a VirtualHost at `acw/public`.
4. Make an admin: register, then `UPDATE users SET role='admin' WHERE username='you';`
   The admin panel is at `/admin/`.

## Conventions

- Every page starts with `require dirname(__DIR__) . '/src/bootstrap.php';` (use `dirname(__DIR__, 2)` inside `public/admin/`).
- Link to pages and assets with `<?= BASE_URL ?>...` in anything shared (templates, admin pages).
- Private files live in `storage/` via `PRIVATE_DIR`, outside `public/`.
