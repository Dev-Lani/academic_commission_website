# Academic Commission

PHP + MySQL marketplace for academic commissions (clients post requests, commissioners send offers, escrow, chat, disputes).

## Folder tree

```
acw/
├── README.md
│
├── public/
│   ├── index.php
│   ├── login.php
│   ├── register.php
│   ├── logout.php
│   ├── dashboard.php
│   ├── profile.php
│   ├── inbox.php
│   ├── transactions.php
│   ├── forum.php
│   ├── post.php
│   ├── create_post.php
│   ├── vote.php
│   ├── offer.php
│   ├── orders.php
│   ├── order.php
│   ├── order_action.php
│   ├── deliver.php
│   ├── preview.php
│   ├── download.php
│   ├── messages.php
│   ├── chat.php
│   ├── chat_list.php
│   │
│   ├── admin/
│   │   ├── index.php
│   │   ├── order.php
│   │   └── action.php
│   │
│   └── assets/
│       ├── css/
│       │   ├── style.css
│       │   ├── ui.css
│       │   ├── chat.css
│       │   ├── dash.css
│       │   ├── forum.css
│       │   ├── order.css
│       │   ├── orders.css
│       │   └── profile.css
│       └── js/
│           ├── chat.js
│           ├── bubble.js
│           ├── nav.js
│           ├── countdown.js
│           └── create_post.js
│
├── src/
│   ├── bootstrap.php
│   ├── config.php
│   ├── database.php
│   ├── cache.php
│   ├── helpers.php
│   ├── auth.php
│   ├── reputation.php
│   ├── order_lib.php
│   └── chat_lib.php
│
├── templates/
│   ├── head.php
│   ├── nav.php
│   └── auth_side.php
│
├── database/
│   └── migrations/
│       ├── 001_users.sql
│       ├── 002_forum.sql
│       ├── 003_offers.sql
│       ├── 004_orders.sql
│       ├── 005_chat.sql
│       ├── 006_chat_reads.sql
│       ├── 007_escrow.sql
│       ├── 008_disputes_ratings.sql
│       └── 009_indexes.sql
│
├── storage/
│   ├── outputs/
│   └── cache/
│
├── tools/
│   ├── seed.php
│   └── monitor.py
│
└── docs/
    └── TEST_PLAN.md
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
