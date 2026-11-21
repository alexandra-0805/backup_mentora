# AI engine (onboarding)

Everything for Mentora's AI-supported onboarding lives in this folder.

```
ai/
├── .htaccess                  blocks web access: nothing here can be opened or downloaded from a browser
├── README.md                  this file
├── bootstrap.php              loads every AI feature (pages require this one file)
├── engine.php                 settings + the call to an AI model (Anthropic, OpenAI-compatible, Gemini, Ollama)
├── text_extract.php           plain text from uploaded resumes (PDF, DOCX, DOC, TXT; pure PHP)
├── skills.php                 skill dictionary, skill detection, department required/lacking skills
├── resume_review.php          Employee VA review: score, category, best-fit department, notifications
├── intern_assessment.php      intern assessment and ranking after training
├── agent_report_summary.php   summary of an agent's employability report for HR
├── config.local.example.php   settings template (provider, key, model name, address)
├── config.local.php           your real settings: a copy of the example, NOT committed to git
└── models/                    local model files, if any (NOT committed to git)
```

## How it decides

Every feature has two layers:

1. **Mentora rules engine** (always on, no key needed). It detects skills, compares them with each
   department's required skills (`admin/department.php`), and scores and categorizes applicants.
   A skill is **lacking** when fewer than `MENTORA_SKILL_TARGET` (2) people in the team have it.
2. **AI model** (optional). When it's set up, the model reads the application or report and writes the
   summary, strengths and concerns. The rules engine still applies the categories, so results stay
   consistent. If the model can't be reached, the rules-engine result is used, so onboarding never stops.

| Feature | Runs when | Result |
|---|---|---|
| Resume review | An Employee VA submits or updates the form or resume; HR clicks "Re-run" | `suitable` (score ≥ 70 and fills a lacking skill), `for_review`, `unsuitable` (score < 40; shown under For review, flagged) |
| Intern assessment | An intern finishes the last training module; the agent clicks "Re-assess" | Score /100, tier (Top performer ≥ 85, Strong ≥ 70, Developing ≥ 55, Needs support), ready = training done and score ≥ 70 |
| Report summary | An agent sends an employability report | Summary for HR, plus a verdict per intern (Employable / Check with agent / Not yet) |

Resume review score (out of 100): best-fit department skills 45, lacking skills filled 15, skill breadth 5,
experience 15, education 10, completeness 10.
Intern score (out of 100): quiz average 35, training 15, hours 15, report quality 15, evaluations 15, attendance 5.

## Setting up an AI model

1. Copy `config.local.example.php` to `config.local.php`.
2. Fill in `provider`, `api_key` and `model` (and `endpoint` for Ollama or another OpenAI-compatible service).
3. The AI screening page (`admin/screening.php`) shows which engine is in use.

Environment variables `MENTORA_AI_PROVIDER`, `MENTORA_AI_API_KEY`, `MENTORA_AI_MODEL` and
`MENTORA_AI_ENDPOINT` override the file. The PHP `curl` extension must be on (it is in XAMPP).

## Keeping it private

- `config.local.php` and the contents of `models/` are listed in the project's `.gitignore`, so
  keys and large model files never end up in git.
- `.htaccess` denies all web requests to this folder, so keys, prompts and model files can't be
  downloaded from `http://localhost/backup_mentora/ai/...`. PHP pages can still `require` files from here.
- Applicant text is sent to the AI model only when one is set up. The prompts tell the model to treat
  applicant text as data, and to judge job-relevant qualifications only.
