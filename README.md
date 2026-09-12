# Computer-Based Assessment (CBA) System

Mobile-friendly online exam system: **PHP + MySQL**. Upload to any web host, open on computer or mobile phone browser. Data lives in **MySQL**, so redeploying/uploading new PHP files **never deletes data**.

## Features (as requested)

- **1 Admin account**: add / edit / delete teacher accounts; manage sections & students; **reset any user password**; view reports.
- **Student self-registration**: asks for **Fullname, Gender, Section, Username, Password**.
- **Teacher**:
  - Create exams (title, section, time limit, passing %, draft/published/closed)
  - **Import exam from Word (.docx)** — see format below
  - **Add / edit / delete own sections** (teacher-created sections are visible only to that teacher)
- **Admin & sections**: admin sees all sections and can **assign a section to a specific teacher** (or leave it shared). Assigned sections are read-only for the teacher.
  - Add / edit / delete questions (MCQ, True/False, Identification, **Essay**)
  - View results per exam + question analysis + **grade essays** (pending list with counts)
- **Reports**: per-exam **scores of students + summary (takers, average, highest 🏆 with name, lowest with name)**; printable.
- **Mobile responsive**: works on phones, tablets, desktops.
- **Timer + auto-submit**, one submission per student, anti-retake.

## Project location

`C:\Users\Admin\Documents\Projects\cba-system`

## Run locally (optional, needs PHP + MySQL)

1. Create DB and import `database/schema.sql` (via phpMyAdmin or MySQL Workbench).
2. Edit `includes/config.php` (DB_HOST / DB_NAME / DB_USER / DB_PASS).
3. Serve: `php -S localhost:8000` from this folder, open `http://localhost:8000/install.php`.
4. Create admin account → **delete `install.php`** → login.

## Deploy to Render (free, mobile-accessible URL)

Render gives you **PostgreSQL** (not MySQL) — this app auto-detects `DATABASE_URL` and uses Postgres there, MySQL elsewhere. No code changes needed.

1. Put this folder on GitHub: github.com → New repository → **Add file → Upload files** → drag in all files from `cba-system` (no git CLI needed).
2. Render.com → **New → Blueprint** → connect that repo. It creates both services from `render.yaml`:
   - `cba-system` (Docker web service, free)
   - `cba-db` (PostgreSQL, free, `DATABASE_URL` wired automatically)
3. Wait for deploy → open `https://cba-system.onrender.com/install.php` → create the **admin account** → **delete `install.php`** (see note below) → login.
4. Share the URL — works on phones and computers.
5. **Data safety**: the Postgres DB is a separate Render service, so redeploys/rebuilds never delete data.

> ⚠️ Deleting `install.php` on Render: after setup, remove the file from your GitHub repo (open file → ⋯ → Delete) — Render auto-redeploys without it. Or leave it; it only recreates tables (IF NOT EXISTS) and upserts admin, never wipes data.
>
> ⏳ Free-plan note: the service sleeps after inactivity — first load takes ~30–60s to wake up. Normal on Render free tier.

## Deploy to web hosting (cPanel / any PHP + MySQL host)

1. **Create MySQL database** in hosting panel (e.g. `school_cba`), plus a MySQL user; note host/user/pass/db.
2. In **phpMyAdmin → Import** `database/schema.sql` (one time only).
3. Upload **all files** in this folder to `public_html/` (or `public_html/cba/`) via File Manager / FTP.
4. Edit `includes/config.php` with your hosting DB credentials — or set env vars `DB_HOST/DB_NAME/DB_USER/DB_PASS`.
5. Visit `https://yourdomain.com/install.php` → create the **single admin account** → **delete `install.php`**.
6. Share the URL — students/teachers open it on **mobile or computer**, register / login, done.
7. **Future updates**: just re-upload PHP files. **Never re-import schema.sql** (that would wipe data). The SQL database is separate from the files, so data persists across deploys.

## Accounts & flow

- `install.php` creates the admin (1 account).
- Admin → Teachers → add teacher accounts.
- Teacher → Sections → add sections (e.g. BSIT-1A).
- Student → Register → pick section → Login → Available Exams → Take → My Scores.
- Admin → Students → reset any password. Admin → Reports → scores/summary/highest/lowest.

## Word import format (.docx)

In Word, type questions like this (blank line between questions), save as `.docx`, upload via Teacher → Exams → Import Word:

```
Q1. What is the capital of the Philippines?
A. Cebu
B. Manila
C. Davao
D. Baguio
Answer: B
Points: 1

Q2. The Earth is flat.
Answer: False
Points: 1

Q3. Who is the national hero of the Philippines?
Answer: Jose Rizal
Points: 2
```

A ready example is in `sample_exam.txt` (copy into Word and save as .docx to test).

## Files

| File | Purpose |
|---|---|
| `index.php` / `register.php` / `dashboard.php` / `logout.php` | Auth + routing |
| `install.php` | One-time setup (delete after use) |
| `admin_teachers.php` / `admin_students.php` / `admin_sections.php` / `admin_reports.php` | Admin panel |
| `teacher_exams.php` / `teacher_questions.php` / `teacher_import.php` / `teacher_results.php` / `teacher_sections.php` | Teacher panel |
| `student_exams.php` / `student_take.php` / `student_scores.php` | Student panel |
| `sections_manage.php` | Shared sections logic (admin + teacher) |
| `includes/` | config, auth, header/footer |
| `assets/` | responsive CSS + exam timer JS |
| `database/schema.sql` | MySQL schema (import once) |
