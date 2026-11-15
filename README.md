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
│   ├── saved.php          (saved requests page, commissioners)
│   ├── bookmark.php       (save / un-save a request)
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
│       │   ├── profile.css
│       │   └── features.css
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
│   └── migration/
│       ├── schema.sql
│       ├── schema_inc2.sql ... schema_inc7.sql
│       └── schema_inc8.sql   (everyday subjects + saved requests)
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

1. Run the migrations in order (`schema.sql`, then `schema_inc2.sql` up to `schema_inc8.sql`).
   With XAMPP: open phpMyAdmin, go to the Import tab, and import each file one at a time in that order.
   Already set up before? Import only the new `schema_inc8.sql`.
2. Edit `src/config.php` if your DB credentials differ.
3. Put the project in `htdocs/acw` (XAMPP). Open `http://localhost/acw/public/`.
   For a cleaner URL, point the Apache DocumentRoot or a VirtualHost at `acw/public`.
4. Make an admin: register, then `UPDATE users SET role='admin' WHERE username='you';`
   The admin panel is at `/admin/`.

## What's new

- **Everyday tasks.** The site is still school-first, but requests can also be everyday tasks
  (design, tutoring, errands, tech help...). Types are grouped in `post_type_groups()` in `src/helpers.php`.
  The forum has School work / Everyday tasks tabs.
- **Saved requests.** Commissioners can star a request (Save button in the forum and on a request page)
  and find it again on the Saved page.

## Conventions

- Every page starts with `require dirname(__DIR__) . '/src/bootstrap.php';` (use `dirname(__DIR__, 2)` inside `public/admin/`).
- Link to pages and assets with `<?= BASE_URL ?>...` in anything shared (templates, admin pages).
- Private files live in `storage/` via `PRIVATE_DIR`, outside `public/`.
