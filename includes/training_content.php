<?php

/**
 * Lesson content for the training modules in mentora_training_modules() (includes/workflow.php),
 * shown on applicant/{intern,employee}/module.php?m=<key>.
 *
 * Each module: summary, objectives, lessons [title, intro, points, tip?], practice checklist,
 * and a quiz [question, options, correct option index, explanation]. Passing the quiz
 * (MENTORA_QUIZ_PASS_PERCENT or better) marks the module as done.
 */

declare(strict_types=1);

const MENTORA_QUIZ_PASS_PERCENT = 75;

function mentora_module_content(string $key): ?array
{
    static $modules = null;
    $modules ??= [
        // ---------------- Shared: both pathways ----------------
        'account_optimization' => [
            'summary' => 'Set up and optimize every account you work in, yours and your clients’, so you look professional, stay secure and work faster.',
            'objectives' => [
                'Present one consistent, professional identity across your work accounts',
                'Protect accounts with a password manager and 2-Step Verification',
                'Configure Gmail, Calendar and notifications for focused work',
                'Manage client accounts through delegated, least-privilege access',
            ],
            'lessons' => [
                ['Your professional identity', 'Clients judge your work before they meet you, from your name, photo and email signature.', [
                    'Complete your Mentora profile: clear photo, skills, mobile number and location.',
                    'Use the same display name and photo on Google, Slack, Zoom and Microsoft Teams.',
                    'Create an email signature: full name, role (e.g. Virtual Assistant), company, time zone.',
                    'Choose a professional username format, e.g. juan.delacruz, never nicknames or numbers that look random.',
                ], 'A friendly, well-lit headshot with a plain background makes clients more comfortable assigning you work.'],
                ['Secure every account', 'You will hold the keys to client inboxes, calendars and files. Treat them like a bank account.', [
                    'Use a password manager (Bitwarden, 1Password or LastPass) with a unique, 14+ character password per account.',
                    'Turn on 2-Step Verification with an authenticator app, not only SMS.',
                    'Add a recovery email and phone, and save backup codes in your password manager.',
                    'Every month, review signed-in devices and remove any you don’t recognize.',
                ], 'Never send a password in chat or email. If a client must share access, ask for delegated access or a shared vault item.'],
                ['Set up your tools for focus', 'A well-configured workspace saves you hours each week.', [
                    'Gmail: create labels per client or project, filters to sort incoming mail, and templates for common replies.',
                    'Google Calendar: set working hours and show both your time zone (PHT) and your client’s.',
                    'Notifications: keep only what needs a reply today (client chat, calendar); mute the rest.',
                    'Use a separate Chrome profile per client so logins, bookmarks and extensions never mix.',
                ], null],
                ['Optimizing client accounts', 'When you manage a client’s accounts, leave them cleaner and safer than you found them.', [
                    'Ask for delegated access (Gmail delegation, shared calendars, Drive sharing) instead of the client’s password.',
                    'Request only the access the task needs (least privilege) and keep a list of what you can access.',
                    'Clean up regularly: archive old threads, unsubscribe from noise, and use a clear folder and file naming system.',
                    'When a project ends, hand back or remove your access and confirm it in writing.',
                ], 'Keep an “Access log” sheet: account, access level, date granted, date removed.'],
            ],
            'practice' => [
                'Update your Mentora profile photo and skills',
                'Turn on 2-Step Verification for your work email',
                'Create an email signature with your role and time zone',
                'Add your client’s time zone to Google Calendar',
                'Create one Gmail filter and one reply template',
            ],
            'quiz' => [
                [
                    'A client asks you to manage their inbox. What is the best way to get access?',
                    [
                        'Ask them to send their password by chat',
                        'Ask for Gmail delegated access',
                        'Create a new shared account',
                        'Log in from their laptop remotely'
                    ],
                    1,
                    'Delegated access lets you work without ever knowing the client’s password, and they can remove it any time.'
                ],
                [
                    'Which 2-Step Verification method is the most secure for daily use?',
                    ['SMS code', 'Authenticator app', 'Security questions', 'Email code'],
                    1,
                    'Authenticator apps can’t be intercepted like SMS and don’t depend on another account.'
                ],
                [
                    'Why use a separate Chrome profile for each client?',
                    [
                        'It makes Chrome faster',
                        'Logins, bookmarks and extensions don’t mix between clients',
                        'It is required by Google',
                        'It hides your browsing history'
                    ],
                    1,
                    'Separate profiles prevent sending from the wrong account and keep each client’s data apart.'
                ],
                [
                    'A project with a client has ended. What should you do with your access?',
                    [
                        'Keep it in case they come back',
                        'Hand back or remove it and confirm in writing',
                        'Change their password',
                        'Share it with your replacement'
                    ],
                    1,
                    'Removing access you no longer need protects both you and the client.'
                ],
            ],
        ],

        // ---------------- Internship pathway ----------------
        'welcome' => [
            'summary' => 'Get to know Mentora PH, how your internship works, and what a great virtual assistant intern does every day.',
            'objectives' => [
                'Explain what Mentora PH does and who its clients are',
                'Know your internship journey and who supports you',
                'Understand the daily routine: time in, tasks, report, time out'
            ],
            'lessons' => [
                ['About Mentora PH', 'Mentora trains and places Filipino virtual assistants with growing businesses abroad.', [
                    'Clients are small and mid-sized businesses that need reliable remote support.',
                    'Virtual assistants handle email, calendars, research, data, customer support and social media.',
                    'Your internship builds the same skills our employee VAs use with real clients.',
                ], null],
                ['Your internship journey', 'Every step is tracked in your workspace so you always know what’s next.', [
                    'Application → evaluation → core training → team assignment → task reporting.',
                    'Your supervising agent approves your reports and hours and writes your evaluations.',
                    'Your required hours come from your school; approved report hours count toward them.',
                ], 'Check your Overview page every morning: it shows your current step and what to do next.'],
                ['Your daily routine', 'Consistency is the most valued VA skill.', [
                    'Mentora uses an open shift: time in on your Reporting page whenever you start working.',
                    'Work on assigned tasks and ask your agent early when you’re blocked.',
                    'Send a daily report with what you finished and the hours worked, then time out.',
                ], null],
            ],
            'practice' => [
                'Read your Overview page and find your current step',
                'Find your supervising agent on Team & agent',
                'Time in on the Reporting page'
            ],
            'quiz' => [
                [
                    'Who approves your daily report hours?',
                    ['Your school', 'Your supervising agent', 'Other interns', 'Nobody, they count automatically'],
                    1,
                    'Your supervising agent reviews and approves each report.'
                ],
                [
                    'What should you do at the end of each shift?',
                    ['Close the browser', 'Send a daily report, then time out', 'Message every client', 'Nothing'],
                    1,
                    'The report records your work and hours; timing out completes your attendance.'
                ],
                [
                    'What counts toward your required internship hours?',
                    ['Hours in approved daily reports', 'Time spent online', 'Messages sent', 'Modules opened'],
                    0,
                    'Only hours from reports your agent approves are counted.'
                ],
            ],
        ],
        'remote_work' => [
            'summary' => 'Work productively and professionally from home: your setup, your schedule and how you communicate.',
            'objectives' => ['Prepare a reliable home workspace', 'Manage your time across time zones', 'Communicate availability clearly'],
            'lessons' => [
                ['Your workspace', 'Clients expect office-level reliability from home.', [
                    'Stable internet (at least 10 Mbps) and a backup such as mobile data.',
                    'A quiet space, headset with microphone, and a webcam for meetings.',
                    'Keep work files in the cloud (Google Drive) so a laptop problem doesn’t stop your work.',
                ], 'Test your backup internet before you need it.'],
                ['Time management', 'Remote work rewards people who plan their day.', [
                    'Start each day with a short list of the three most important tasks.',
                    'Use time blocks for focused work and batch small tasks like email.',
                    'Always note deadlines in the client’s time zone and in PHT.',
                ], null],
                ['Showing you’re available', 'Silence makes remote teams nervous.', [
                    'Keep your chat status accurate: available, in a meeting, away.',
                    'Reply to messages within your agreed response time, even just “Got it, working on it.”',
                    'Tell your agent in advance about brownouts, appointments or leave.',
                ], null],
            ],
            'practice' => ['Run an internet speed test and note the result', 'Write tomorrow’s top three tasks', 'Set your chat status and working hours'],
            'quiz' => [
                [
                    'Your internet goes down during your shift. What should you do first?',
                    ['Wait until it comes back', 'Switch to your backup and tell your agent', 'End your shift', 'Post on social media'],
                    1,
                    'Switching to a backup and informing your agent keeps work and trust going.'
                ],
                [
                    'A client deadline is “5 PM EST”. How should you note it?',
                    ['Only as 5 PM', 'In EST and in PHT', 'In PHT only', 'Ask someone later'],
                    1,
                    'Writing both prevents missed deadlines across time zones.'
                ],
                [
                    'What is a good reply when you receive a task but can’t finish it right away?',
                    ['No reply until it’s done', '“Got it, I’ll have it by 3 PM.”', '“Busy.”', 'A thumbs-up only'],
                    1,
                    'Acknowledging with a time sets clear expectations.'
                ],
            ],
        ],
        'workspace_files' => [
            'summary' => 'Use Google Workspace like a professional VA and keep every file easy to find.',
            'objectives' => ['Organize Drive with clear folders and names', 'Share files safely', 'Collaborate in Docs and Sheets'],
            'lessons' => [
                ['Folder structure', 'A predictable structure means anyone can find anything.', [
                    'One top folder per client, then folders by project or function (Admin, Finance, Marketing).',
                    'Keep an “Archive” folder for finished work instead of deleting it.',
                    'Never leave files loose in “My Drive”.',
                ], null],
                ['Naming files', 'Good names sort themselves.', [
                    'Format: YYYY-MM-DD_Client_Topic_v01, e.g. 2026-11-20_Acme_Invoice-Tracker_v02.',
                    'Use dates in year-month-day order so files sort by date.',
                    'Avoid “final”, “final2”, “new” in file names; use version numbers.',
                ], 'Agree the naming format with your client on day one.'],
                ['Sharing and collaboration', 'Share deliberately; every link is a door.', [
                    'Share with specific people; avoid “Anyone with the link” for client data.',
                    'Use Viewer or Commenter unless someone truly needs to edit.',
                    'Use comments and suggestions in Docs instead of changing text silently.',
                ], null],
            ],
            'practice' => [
                'Create a sample client folder structure',
                'Rename three files using the naming format',
                'Share a test file as Viewer with your agent'
            ],
            'quiz' => [
                [
                    'Which file name follows the standard?',
                    ['final invoice NEW.xlsx', '2026-11-20_Acme_Invoice-Tracker_v02', 'invoice(1).xlsx', 'Acme stuff'],
                    1,
                    'Date first, client, topic and a version number.'
                ],
                [
                    'Which sharing setting is best for a client’s payroll sheet?',
                    ['Anyone with the link can edit', 'Specific people, minimum access needed', 'Public on the web', 'Anyone in Gmail'],
                    1,
                    'Sensitive files should be shared only with named people at the lowest access level.'
                ],
                [
                    'Where should finished project files go?',
                    ['Delete them', 'An Archive folder', 'Your desktop', 'Loose in My Drive'],
                    1,
                    'Archiving keeps history without cluttering active work.'
                ],
            ],
        ],
        'client_basics' => [
            'summary' => 'Write and speak to clients clearly, politely and confidently.',
            'objectives' => ['Write clear, professional emails', 'Ask good clarifying questions', 'Give status updates without being asked'],
            'lessons' => [
                ['Clear emails', 'Busy clients read the first two lines. Make them count.', [
                    'Subject line says what it is about and if action is needed, e.g. “Action needed: approve Friday’s schedule”.',
                    'Put the request or answer first, details after.',
                    'Short paragraphs, bullet points, and a clear closing line.',
                ], null],
                ['Clarifying questions', 'Asking early is cheaper than redoing work.', [
                    'Repeat the task in your own words and confirm the deadline.',
                    'Ask for an example of what “done” looks like.',
                    'Group your questions into one message instead of many.',
                ], null],
                ['Status updates', 'Proactive updates build trust faster than anything else.', [
                    'Use the format: Done · In progress · Blocked / need from you.',
                    'Send updates at agreed times, e.g. end of day.',
                    'Flag risks early, with a proposed solution.',
                ], 'Good news can wait for the end-of-day update; problems should be shared right away.'],
            ],
            'practice' => ['Rewrite a sample email with the request first', 'Write three clarifying questions for a sample task', 'Draft an end-of-day update'],
            'quiz' => [
                [
                    'What belongs in the first line of a client email?',
                    ['A long greeting', 'The request or answer', 'Your schedule', 'An apology'],
                    1,
                    'Clients scan the top first; lead with what matters.'
                ],
                [
                    'You’re unsure what a task needs. When should you ask?',
                    ['After finishing a first version', 'Right away, grouped in one message', 'Never, figure it out', 'At the deadline'],
                    1,
                    'Early, grouped questions save time for both of you.'
                ],
                [
                    'Which is the recommended update format?',
                    ['Done · In progress · Blocked', 'A single emoji', 'Only when asked', 'A screenshot only'],
                    0,
                    'This format shows progress and what you need at a glance.'
                ],
            ],
        ],
        'data_privacy' => [
            'summary' => 'Protect client and personal data under the Philippine Data Privacy Act and client policies.',
            'objectives' => ['Recognize personal and sensitive information', 'Handle data safely day to day', 'Respond correctly to a possible breach'],
            'lessons' => [
                ['What counts as personal data', 'The Data Privacy Act of 2012 (RA 10173) protects personal information.', [
                    'Personal data: names, emails, phone numbers, addresses, IDs.',
                    'Sensitive data: health, government IDs, financial and payment details.',
                    'Client business data (prices, contracts, customer lists) is confidential too.',
                ], null],
                ['Everyday safe handling', 'Most leaks are accidents, not hackers.', [
                    'Only collect and open the data your task needs.',
                    'Never download client data to personal devices or USB drives.',
                    'Lock your screen when you step away, even at home.',
                    'Double-check recipients before sending attachments.',
                ], 'If an email asks you to log in or pay urgently, verify through another channel first.'],
                ['If something goes wrong', 'Fast reporting limits the damage.', [
                    'Report a lost device, wrong recipient or suspicious login to your agent immediately.',
                    'Don’t try to hide or fix it alone.',
                    'Write down what happened and when.',
                ], null],
            ],
            'practice' => ['List three types of sensitive data you might handle', 'Set your screen to lock after 5 minutes', 'Find who to report incidents to'],
            'quiz' => [
                [
                    'Which of these is sensitive personal information?',
                    ['A company logo', 'A customer’s government ID number', 'A public website', 'A meeting title'],
                    1,
                    'Government IDs are sensitive personal information under RA 10173.'
                ],
                [
                    'You emailed a file to the wrong person. What now?',
                    ['Hope they don’t open it', 'Report it to your agent right away', 'Delete your sent copy', 'Send it again correctly and say nothing'],
                    1,
                    'Immediate reporting allows the team to contain the incident.'
                ],
                [
                    'Where may you store client data?',
                    ['Personal USB drive', 'The client’s approved cloud folders', 'Your phone gallery', 'A personal email'],
                    1,
                    'Keep data only in the client-approved locations.'
                ],
            ],
        ],
        'support_simulation' => [
            'summary' => 'Practice a realistic day of client support: tickets, replies, escalation and handover.',
            'objectives' => ['Prioritize a queue of requests', 'Reply using templates while sounding human', 'Escalate and hand over properly'],
            'lessons' => [
                ['Prioritizing a queue', 'Not every message is equally urgent.', [
                    'Urgent: blocks a customer or client from working or paying.',
                    'High: has a deadline today.',
                    'Normal: everything else, in order received.',
                ], null],
                ['Replying well', 'Templates are a starting point, not the final message.', [
                    'Use the customer’s name and refer to their exact issue.',
                    'Say what you did and what happens next, with a time.',
                    'Close by asking if anything else is needed.',
                ], null],
                ['Escalation and handover', 'Know when it’s not yours to solve.', [
                    'Escalate refunds, legal questions and angry VIPs to your agent or the client.',
                    'Write a handover note: open items, status, next step, links.',
                    'Record the day’s work in your daily report.',
                ], 'A good handover note means the next person never has to ask you a question.'],
            ],
            'practice' => ['Sort five sample requests by priority', 'Personalize one reply template', 'Write a handover note for your shift'],
            'quiz' => [
                [
                    'A customer can’t pay their invoice because the link is broken. Priority?',
                    ['Normal', 'Urgent', 'Low', 'Ignore'],
                    1,
                    'It blocks payment, so it’s urgent.'
                ],
                [
                    'A customer asks for a refund outside policy. You should…',
                    ['Approve it to keep them happy', 'Escalate to your agent or the client', 'Ignore the message', 'Close the ticket'],
                    1,
                    'Refund exceptions are escalated to someone with authority.'
                ],
                [
                    'What makes a good handover note?',
                    ['Open items, status, next step and links', 'A summary of your mood', 'Nothing, they can check email', 'Only the time you left'],
                    0,
                    'The next person can continue without asking you.'
                ],
            ],
        ],

        // ---------------- Employee VA pathway ----------------
        'policies' => [
            'summary' => 'Mentora’s working policies: schedule, attendance, conduct, confidentiality and how you’re paid for approved work.',
            'objectives' => ['Work an open shift and record your time', 'Apply the code of conduct with clients', 'Know how reports and evaluations work'],
            'lessons' => [
                ['Open shift and attendance', 'You choose when you work; clients still count on you to be reachable.', [
                    'Open shift: work any day and any time that fits you and your client’s deadlines; there is no late time.',
                    'Time in on your Reporting page when you start and time out when you finish, so your hours are recorded.',
                    'Request leave in advance through HR; emergencies are reported as soon as possible.',
                ], null],
                ['Conduct and confidentiality', 'You represent Mentora in every client interaction.', [
                    'Be respectful, responsive and honest about what you can deliver.',
                    'Client information stays confidential, during and after your engagement.',
                    'Don’t work directly with a Mentora client outside your agreement.',
                ], null],
                ['Reports, evaluations and growth', 'Your record speaks for you.', [
                    'Send a daily work report; HR reviews and approves your hours.',
                    'Evaluations rate client communication, tools, accuracy, problem solving and values.',
                    'Feedback from reviews appears on your Department page.',
                ], 'A consistent daily report is the easiest way to show your value to a client.'],
            ],
            'practice' => ['Agree your usual working hours with your client', 'Time in on the Reporting page', 'Read your Department page'],
            'quiz' => [
                [
                    'How does Mentora’s open shift work?',
                    ['You must start at 8:00 AM every day', 'You work any time; time in when you start and time out when you finish', 'You only work on weekends', 'You never need to record your time'],
                    1,
                    'There are no fixed hours and no late time; timing in and out records the hours you worked.'
                ],
                [
                    'A client asks you to work for them directly outside Mentora. You…',
                    ['Agree quietly', 'Decline and inform HR', 'Ask for a higher rate', 'Ignore it'],
                    1,
                    'Direct engagements outside your agreement aren’t allowed; tell HR.'
                ],
                [
                    'Who approves employees’ daily report hours?',
                    ['The client', 'HR', 'Other employees', 'Nobody'],
                    1,
                    'HR reviews employee reports in Monitoring.'
                ],
            ],
        ],
        'va_workflow' => [
            'summary' => 'The core executive-assistant workflow: inbox, calendar, tasks and travel, done the same reliable way every day.',
            'objectives' => ['Run an inbox-zero routine', 'Manage complex calendars', 'Track tasks so nothing is forgotten'],
            'lessons' => [
                ['Inbox management', 'Your client’s inbox is their to-do list. Keep it under control.', [
                    'Process mail at set times: reply, delegate, file or delete. Don’t just read.',
                    'Use labels such as Action, Waiting for, and Read later.',
                    'Draft replies for the client to approve when you’re not sure of the tone.',
                ], null],
                ['Calendar management', 'A good calendar protects the client’s focus.', [
                    'Add buffers between meetings and protect focus blocks.',
                    'Every event has a clear title, location or video link, and an agenda.',
                    'Confirm next-day meetings each afternoon and flag conflicts early.',
                ], null],
                ['Task tracking', 'If it isn’t written down, it doesn’t exist.', [
                    'Keep one task list (Asana, Trello, ClickUp or a Sheet) agreed with the client.',
                    'Each task has an owner, due date and status.',
                    'Review the list at the start and end of each day.',
                ], 'Send your client a short “tomorrow’s schedule” summary at the end of each day.'],
            ],
            'practice' => [
                'Set up Action / Waiting for / Read later labels',
                'Add buffers to a sample calendar',
                'Create a task list with owner, due date and status'
            ],
            'quiz' => [
                [
                    'What are the four inbox actions?',
                    ['Read, star, mark unread, wait', 'Reply, delegate, file, delete', 'Print, scan, forward, archive', 'Open, close, flag, snooze'],
                    1,
                    'Every email should end in one of these actions.'
                ],
                [
                    'Why add buffers between meetings?',
                    ['To make the calendar look full', 'To protect time for preparation and overruns', 'Clients require it', 'To avoid meetings'],
                    1,
                    'Buffers prevent back-to-back delays.'
                ],
                [
                    'What does every tracked task need?',
                    ['A color', 'Owner, due date and status', 'An emoji', 'A meeting'],
                    1,
                    'These three fields make tasks trackable.'
                ],
            ],
        ],
        'client_standards' => [
            'summary' => 'Mentora’s communication standards for written and live conversations with clients.',
            'objectives' => [
                'Match the client’s tone while staying professional',
                'Run short, useful check-in calls',
                'Handle difficult conversations calmly'
            ],
            'lessons' => [
                ['Tone and clarity', 'Professional doesn’t mean stiff.', [
                    'Mirror the client’s level of formality.',
                    'Lead with the answer; keep messages short and scannable.',
                    'Proofread names, numbers and dates before sending.',
                ], null],
                ['Check-in calls', 'Respect the client’s time.', [
                    'Send a 3-point agenda before the call.',
                    'Recap decisions and next steps in writing after the call.',
                    'Keep routine check-ins to 15 minutes.',
                ], null],
                ['Difficult conversations', 'Calm and factual wins.', [
                    'Acknowledge the concern before explaining.',
                    'Offer options and a clear next step.',
                    'Escalate to HR if a conversation becomes abusive.',
                ], 'Write “I understand this is frustrating. Here’s what I’ll do next…”'],
            ],
            'practice' => ['Write a 3-point agenda for a check-in', 'Write a post-call recap', 'Draft a reply to an upset client'],
            'quiz' => [
                [
                    'What should you send after a check-in call?',
                    ['Nothing', 'A written recap of decisions and next steps', 'An invoice', 'A meme'],
                    1,
                    'Written recaps prevent misunderstandings.'
                ],
                [
                    'A client is upset about a delay. First you…',
                    ['Explain why it’s not your fault', 'Acknowledge the concern', 'Go silent', 'Escalate immediately'],
                    1,
                    'Acknowledging first calms the conversation.'
                ],
                [
                    'How long should a routine check-in be?',
                    ['About 15 minutes', 'One hour', 'As long as possible', 'Two minutes'],
                    0,
                    'Short check-ins respect the client’s time.'
                ],
            ],
        ],
        'security' => [
            'summary' => 'Information security and privacy for VAs with access to client systems and data.',
            'objectives' => ['Spot phishing and social engineering', 'Secure devices and connections', 'Follow the incident procedure'],
            'lessons' => [
                ['Phishing and scams', 'Attackers target assistants because they have access.', [
                    'Check the sender’s real address, not just the display name.',
                    'Be suspicious of urgency, gift-card or payment-change requests.',
                    'Verify unusual requests with the client by another channel.',
                ], null],
                ['Devices and connections', 'Your laptop is part of the client’s security.', [
                    'Keep your system and browser updated; use antivirus.',
                    'Avoid public Wi-Fi for client work, or use a VPN.',
                    'Encrypt your device and use a strong login password.',
                ], null],
                ['Privacy and incidents', 'RA 10173 applies to the data you handle.', [
                    'Access only what the task requires.',
                    'Report suspected incidents to HR immediately.',
                    'Never install unapproved tools on client accounts.',
                ], 'A request to change bank details is always verified by phone or video call.'],
            ],
            'practice' => ['Check your device for updates', 'Review a sample email for phishing signs', 'Find the incident reporting contact'],
            'quiz' => [
                [
                    '“CEO” emails asking you to buy gift cards urgently. You…',
                    ['Buy them quickly', 'Verify with the client through another channel', 'Reply asking for the amount', 'Forward to everyone'],
                    1,
                    'This is a classic scam; verify separately.'
                ],
                [
                    'Safe way to work from a café?',
                    ['Public Wi-Fi as is', 'Mobile hotspot or VPN', 'Any open network', 'Don’t lock your screen'],
                    1,
                    'Hotspots and VPNs protect your connection.'
                ],
                [
                    'A client asks to change a supplier’s bank details by email. You…',
                    ['Update immediately', 'Verify by phone or video call first', 'Ignore', 'Ask the supplier by email'],
                    1,
                    'Payment changes are verified through a separate channel.'
                ],
            ],
        ],
        'handoff' => [
            'summary' => 'Hand over work between shifts, teammates and clients without losing anything.',
            'objectives' => ['Write complete handover notes', 'Document recurring processes', 'Close the day cleanly'],
            'lessons' => [
                ['Handover notes', 'The next person should never have to ask you a question.', [
                    'Open items with status, owner and next step.',
                    'Links to every file, ticket or thread mentioned.',
                    'Deadlines and anything promised to the client.',
                ], null],
                ['Process documentation', 'Write it once, save hours later.', [
                    'Create a step-by-step SOP for any task you do more than twice.',
                    'Include screenshots and the reason behind each step.',
                    'Keep SOPs in the client’s shared folder and update them when things change.',
                ], null],
                ['Closing the day', 'End every shift the same way.', [
                    'Update the task list and send the client a short summary.',
                    'Send your daily report in Mentora.',
                    'Time out.',
                ], 'Use the summary format: Done · In progress · Needs your input.'],
            ],
            'practice' => ['Write a handover note for a sample shift', 'Draft an SOP for one recurring task', 'Send your daily report'],
            'quiz' => [
                [
                    'What must a handover note include?',
                    ['Open items, status, next step and links', 'Only completed tasks', 'Your personal notes', 'Nothing if you’re busy'],
                    0,
                    'Those details let the next person continue immediately.'
                ],
                [
                    'When should you write an SOP?',
                    ['Never', 'For any task done more than twice', 'Only when asked', 'After leaving'],
                    1,
                    'Repeated tasks benefit most from documentation.'
                ],
                [
                    'Last steps of the day?',
                    ['Update tasks, send summary and daily report, time out', 'Just close the laptop', 'Time out only', 'Email the CEO'],
                    0,
                    'A consistent close keeps everyone informed.'
                ],
            ],
        ],
    ];

    return $modules[$key] ?? null;
}
