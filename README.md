# Mentora PH

A web-based virtual assistant HR recruitment system with AI-supported onboarding for Mentora Philippines.
It runs on PHP 8.2 and MariaDB/MySQL (XAMPP). The database tables create and upgrade themselves on the
first page load (`config/db.php`), so there is no migration step.

## How the system works

### Recruitment (agent → applicant)
1. An **agent** creates a recruitment link (`agent/recruitment.php`) and sends it, like a form link.
2. The applicant opens it (`auth/join.php`) and **signs in or creates an account** (username + password, or Google).
3. They choose **Intern** or **Employee VA** (`auth/pathway.php`). Each pathway has its own form.

Applicants can still apply on the public page instead (`auth/signup.php`). That path waits for an HR
approval link (`admin/applications.php` → `auth/activate.php`).

### Employee VA: AI-supported resume review
1. The applicant **fills in the form** (role, education, experience, skills) **or uploads a resume** (PDF, DOCX, DOC, TXT).
2. The AI reviews it (`ai/resume_review.php`) and matches the applicant's skills to the skills each department is lacking:
   - **Suitable**: score ≥ 70 and fills a lacking skill. HR **and that department's agents** are notified so they can assess right away.
   - **For review**: HR reviews it, with a summary of skills and educational attainment.
   - **Flagged unsuitable**: score < 40. It stays under For review but is flagged.
3. Department agents endorse (or don't) to HR (`agent/candidates.php`). HR accepts the applicant into a department or declines (`admin/screening.php`).

### Intern: training, AI assessment, agent handling
1. The intern fills the internship form and **goes straight to training**. The agent who sent the link becomes their supervising agent.
2. Training progress is tracked per module (`agent/training_tracker.php`).
3. After the last module, the **AI assesses and ranks the intern** (`ai/intern_assessment.php`), and the agent is notified (`agent/intern_assessments.php`).
4. The agent sends an **employability report** recommending interns (`agent/agent_reports.php`). The AI summarizes it and checks each recommendation against the assessment.
5. HR sees the summaries and the **interns ready to employ** (`admin/intern_reports.php`).

### Reports
- `admin/consolidated_report.php` shows recruitment, AI onboarding, training, hours, evaluations, attendance and department skill gaps (print, PDF or CSV).
- CSV exports: `admin/export.php?type=screening|summary|…` and `agent/export.php?type=assessments|…`.

## Folders

```
admin/            HR workspace: dashboard, applications, AI screening, people lists, departments
                  (required skills), monitoring, intern reports, consolidated reports, users & access
agent/            Agent workspace: recruitment links, department candidates, interns, training tracker,
                  AI assessments, evaluations, employability reports, reporting, documents
applicant/        Applicant workspaces: intern/ and employee/ (each page is a small wrapper around
                  includes/pages/ or includes/application_form.php)
account/          Shared signed-in pages: messages, notifications, protected file downloads
auth/             Sign in / out, apply, join with a recruitment link, choose pathway, activate, Google
ai/               AI engine: settings, model calls, resume text, skills, resume review, intern
                  assessment, report summaries (see ai/README.md; not reachable from the web)
includes/         Shared PHP: layouts, workflow helpers, recruitment, screening, intern reports, forms
  pages/          Page bodies shared by the intern and employee workspaces
config/           Database connection + migrations, local settings (Google, mail)
assets/           CSS, JavaScript, images
uploads/          Avatars, documents (resumes, certificates), message files (not reachable directly)
```

## Accounts and roles
- **admin**: the HR administrator. Approves applications, makes screening decisions, manages departments and access.
- **agent**: recruits with links, supervises interns, assesses department candidates, writes employability reports.
- **applicant**: an intern (`internship`) or an Employee VA (`employee_va`).

## AI setup
Out of the box, the built-in **Mentora rules engine** does every AI step: skill matching, scoring and
summaries. To have an AI model (Anthropic, OpenAI-compatible, Gemini or a local Ollama model) write the
summaries, copy `ai/config.local.example.php` to `ai/config.local.php` and fill it in. See `ai/README.md`.
