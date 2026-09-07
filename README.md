# BondNest

**BondNest** is a social networking web application built with vanilla PHP and PostgreSQL. Registered users create text and image posts, like and comment, exchange direct messages, and receive moderation notifications. Administrators review content through a dashboard that supports approving, placing on hold, warning, and deleting posts.

The system was developed as a **second-year level, unfinished project**. It is not feature-complete: several flows are partial, debug/test artifacts remain in the repository, and some pages contain dead links or stub scripts. This project is documented as implemented, including its gaps.

**Author:** Tolentino, Lawrence Dave P.

---

## Key Features

- **User registration and authentication:** Registration with email OTP verification (Brevo), session-based login with username or email, password recovery via OTP, and password change with strength enforcement.
- **Post creation and moderation:** Users publish text/image posts with edit and delete. Admins approve, hold, warn, or delete posts with reasons. Moderation outcomes generate notifications with post snapshots.
- **Likes and comments:** Toggle likes with counts, threaded comments and replies, edit and delete own comments.
- **User profiles:** Profile pages with photo, bio, personal details, and per-user post lists with moderation badges.
- **Direct messaging:** 1:1 messaging with inbox, conversation view, user search, read markers, and unread badges.
- **Notifications:** Bell notification system with paginated dropdown (5 per page), deep-linking to typed inboxes (approved, held, warnings, deleted) with Clear-Read support.
- **Online presence:** Active now / Away / Last seen status derived from activity heartbeats.
- **Admin dashboard:** Statistics cards, searchable post table, and approve/hold/delete/warn moderation actions with audit logging.
- **Server-clock-synced timestamps:** Post age labels ("N seconds ago") are kept live using server time synchronization.

---

## Tech Stack

- **Frontend:** Server-rendered PHP templates, custom CSS, vanilla JavaScript; Bootstrap Icons 1.11, Font Awesome 6
- **Backend:** Vanilla PHP 8.1+ (deployed on PHP 8.3) + PDO; no framework; idempotent `migrate.php` bootstrap
- **Database:** PostgreSQL on Railway (production); MySQL locally under XAMPP
- **Authentication:** PHP sessions, `password_hash()` / `password_verify()`, six-digit email OTPs
- **Email / OTP:** Brevo transactional HTTP API
- **Hosting:** [Railway](https://bondnest.up.railway.app/) (PHP built-in server + PostgreSQL)
- **Version control:** [GitHub](https://github.com/rproject1324/BondNest)

---

## How It Works

1. **Registration and verification:** Users sign up with username, email, names, gender, birthday, and a password. A six-digit OTP is sent via Brevo for email verification before account activation.
2. **Posting and feed:** Authenticated users create text/image posts (status `posted`). The homepage displays an approved/posted feed with like, comment, and edit controls.
3. **Moderation:** Admins review posts through a dashboard. Actions include approve (`→ approved`), hold (`→ on-hold`), warn (snapshot + notification), or delete (snapshot + notification, then row removal).
4. **Notifications:** Moderation outcomes create typed notifications. The navbar bell fetches paginated notifications, marks displayed items as read, and deep-links each type to its inbox.
5. **Direct messaging:** Users exchange 1:1 messages stored with read flags and soft delete. Unread counts appear as badges in the inbox.

### Environment-Driven Configuration

The same codebase runs locally on MySQL (XAMPP) and in production on PostgreSQL (Railway). `DATABASE_URL` triggers PostgreSQL mode; without it, the app defaults to local MySQL. Secrets (API keys, database URLs) live in Railway Variables, never in git.

---

## Installation Instructions

### Prerequisites

- PHP 8.1+ with `pdo_pgsql`, `pdo_mysql`, `mbstring`, `fileinfo`, `json`
- MySQL (XAMPP) for local development, or PostgreSQL for production
- Git

### 1. Clone the repository

```bash
git clone https://github.com/rproject1324/BondNest.git
cd BondNest
```

### 2. Configure environment (optional for local dev)

Without `DATABASE_URL`, the app uses local MySQL (`bondnest_db`, root, empty password). `migrate.php` creates/patchtables on boot.

| Variable | Purpose |
|----------|---------|
| `DATABASE_URL` | PostgreSQL connection string (triggers pgsql mode) |
| `DB_HOST` / `DB_PORT` / `DB_NAME` / `DB_USER` / `DB_PASS` | Database parts (defaults to local MySQL) |
| `UPLOADS_DIR` | Persistent image directory (Railway volume in production) |
| `BONDNEST_ADMIN_EMAIL` | Admin allow-list for auto-promotion (comma-separated) |
| `BREVO_API_KEY` | Brevo HTTP API key |
| `BREVO_SENDER_EMAIL` | From address (Brevo verified sender) |
| `BREVO_SENDER_NAME` | From display name (default: BondNest) |
| `PORT` | HTTP listen port (provided by Railway) |

Leave Brevo keys unset for dev-mode OTP logging (codes logged to server).

### 3. Run locally

```bash
php -S 127.0.0.1:8000
```

Open **http://127.0.0.1:8000** and register a new account. Without Brevo keys, OTPs are logged to the server console.

### Production deploy (Railway)

1. Connect [https://github.com/rproject1324/BondNest](https://github.com/rproject1324/BondNest) to a Railway project.
2. Add the PostgreSQL plugin (`DATABASE_URL` is injected automatically).
3. Mount a volume and set `UPLOADS_DIR` so photos survive redeploys.
4. Set the environment variables above in Railway Variables (never in git).
5. The `Procfile` starts the PHP built-in server: `php -S 0.0.0.0:$PORT -t .`

Live app: **https://bondnest.up.railway.app/**

---

## Author

Tolentino, Lawrence Dave P.

---

## Project Links

- **Live Deployment (Railway):** https://bondnest.up.railway.app/
- **GitHub Repository:** https://github.com/rproject1324/BondNest
- **System Documentation (PDF):** `BondNest_System_Documentation.pdf`

---

## Project Structure (overview)

| Path | Description |
|------|-------------|
| `index.php` | Sign-in page and login/forgot-password flows |
| `signup.php` | User registration with inline validation |
| `verify-registration.php` | Email OTP verification |
| `homepage.php` | Homepage feed with create-post modal |
| `profile-page.php` | User profiles (own + others) |
| `settings.php` | Personal info, photo, bio, email/password change |
| `message.php` | Direct messaging inbox and conversation view |
| `admin.php` | Admin dashboard with moderation actions |
| `approved_posts.php` | Approved post notification inbox |
| `held_posts.php` | Held post notification inbox |
| `warnings.php` | Warning notification inbox |
| `deleted_posts.php` | Deleted post notification inbox |
| `navbar.php` | Shared navigation and notification bell |
| `db_connection.php` | Database connection and admin detection |
| `migrate.php` | Idempotent table creation/patching |
| `get_notifications.php` | Notification polling endpoint |
| `login-signup.css` | Auth page styles |
| `settings.css` | Settings page styles |
| `BondNest_System_Documentation.pdf` | System documentation (10 pages) |

---

*Created as a second-year project exploring social networking features with vanilla PHP and PostgreSQL.*
