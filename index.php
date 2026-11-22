<?php

require_once __DIR__ . '/config/db.php';
require_once __DIR__ . '/includes/feedback.php';

// "What people are saying": overall rating + the 4 newest reviews. Posting happens on feedback.php.
$feedbackSummary = mentora_feedback_summary($pdo);
$homeReviews = mentora_feedback_list($pdo, 4);

$pageTitle = 'Mentora';
$bodyClass = 'landing-page';

// Icons are shared with the About us page (includes/icons.php).
require_once __DIR__ . '/includes/icons.php';
$icon = 'mentora_icon';

require __DIR__ . '/includes/header.php';
?>

<main>
    <?php if (isset($_GET['submitted'])): ?>
        <div class="landing-notice" role="status">
            <strong>Application submitted.</strong>
            Your account is pending approval. We’ll review your details and contact you by email.
        </div>
    <?php endif; ?>
    <section class="hero">
        <div class="hero-content">
            <p class="eyebrow">Recruitment to development</p>
            <h1>Your potential,<br><em>our shared future.</em></h1>
            <p class="hero-copy">
                Mentora connects recruitment, onboarding, training, and development
                in one thoughtful workspace built for people and progress.
            </p>
            <div class="actions">
                <a class="button button-primary" href="#pathways">
                    Explore
                </a>
                <a class="button button-ghost" href="auth/signin.php">
                    Sign in
                </a>
            </div>
            <p class="hero-note">For applicants, mentors, and people teams</p>
        </div>
    </section>
    <section id="pathways" class="section" aria-labelledby="pathways-title">
        <div class="section-heading">
            <div>
                <h2 id="pathways-title">Start where you are.<br>Grow toward what’s next.</h2>
            </div>

        </div>
        <div class="card-grid two">
            <article class="feature-card">
                <div class="card-topline">
                    <span class="icon"><?= $icon('briefcase') ?></span>
                    <span class="tag">Build your career</span>
                </div>
                <h3>Employee VA</h3>
                <p>
                    Put your skills to work with a remote-first team supporting
                    global clients.
                </p>
                <ul>
                    <li>Relevant administrative or client support skills</li>
                    <li>40 hours per week with virtual support</li>
                    <li>Comfortable collaborating in English</li>
                </ul>
            </article>
            <article class="feature-card">
                <div class="card-topline">
                    <span class="icon"><?= $icon('graduation') ?></span>
                    <span class="tag">Learn by doing</span>
                </div>
                <h3>Internship</h3>
                <p>
                    Turn your classroom knowledge into meaningful, supervised work
                    experience.
                </p>
                <ul>
                    <li>Enrolled in a college or university</li>
                    <li>School-accredited internship</li>
                    <li>Can commit at least 20 hours per week</li>
                </ul>
            </article>
        </div>
        <div class="pathways-apply">
            <a class="text-button" href="auth/signup.php">
                Apply now
            </a>
        </div>
    </section>

    <section class="section pale" aria-labelledby="support-title">
        <div class="section-heading">
            <div>
                <h2 id="support-title">Learning that connects<br>to real work.</h2>
            </div>
            <p class="section-intro hover-lift-text">
                Get the context, guidance, and feedback to take your next step with
                confidence.
            </p>
        </div>
        <div class="card-grid three">
            <article class="mini-card">
                <span class="icon"><?= $icon('book') ?></span>
                <h3>Structured training</h3>
                <p>Build role-specific skills through a guided learning plan.</p>
            </article>
            <article class="mini-card">
                <span class="icon"><?= $icon('message-heart') ?></span>
                <h3>Mentor guidance</h3>
                <p>Connect with your assigned mentor for questions and feedback.</p>
            </article>
            <article class="mini-card">
                <span class="icon"><?= $icon('check-circle') ?></span>
                <h3>Thoughtful evaluation</h3>
                <p>Understand your progress with clear reviews and next steps.</p>
            </article>
        </div>
    </section>

    <section class="section preparation" aria-labelledby="preparation-title">
        <div class="preparation-copy">
            <h2 id="preparation-title">Bring your story.<br>We’ll help with the next step.</h2>
            <p>Keep these details ready before starting your application.</p>
        </div>
        <div class="preparation-list">
            <div>
                <span><?= $icon('document') ?></span><strong>Employee VA</strong><small>An updated résumé, experience, skills, and availability.</small>
            </div>
            <div><span><?= $icon('calendar') ?></span><strong>Internship</strong><small>School details, required hours, and placement dates.</small></div>
            <div>
                <span><?= $icon('contact') ?></span><strong>Both pathways</strong><small>A current email address and accurate contact information.</small>
            </div>
        </div>
    </section>

    <!-- About us preview: the whole section links to about.php. -->
    <section class="section about-teaser" aria-labelledby="about-teaser-title">
        <div class="about-teaser-inner">
            <div class="about-teaser-copy">
                <p class="eyebrow">About us</p>
                <h2 id="about-teaser-title">Get to know Mentora.</h2>
                <p>
                    Mentora is one workspace for the whole journey: recruitment, onboarding,
                    training, and development. We built it so applicants, mentors, and people
                    teams can grow together.
                </p>
                <ul class="about-teaser-values">
                    <li><?= $icon('sprout') ?>Growth first</li>
                    <li><?= $icon('message-heart') ?>Guidance, not guesswork</li>
                    <li><?= $icon('shield-check') ?>Privacy &amp; trust</li>
                </ul>
                <a class="button about-teaser-link" href="about.php">Learn more about us
                    
                </a>
            </div>

            <div class="about-teaser-visual" aria-hidden="true">
                <div class="about-teaser-logo">
                    <img src="assets/images/mentora-logo.png" alt="">
                    <span>Mentora</span>
                </div>
                <ol class="about-teaser-journey">
                    <li><span class="icon"><?= $icon('document') ?></span><strong>Recruit</strong><small>One simple application</small></li>
                    <li><span class="icon"><?= $icon('book') ?></span><strong>Train</strong><small>Guided learning plans</small></li>
                    <li><span class="icon"><?= $icon('sprout') ?></span><strong>Grow</strong><small>Mentoring &amp; evaluation</small></li>
                </ol>
            </div>
        </div>
    </section>

    <section id="feedback" class="section feedback-section" aria-labelledby="feedback-title">
        <div class="feedback-heading">
            <div>
                <p class="eyebrow">Feedback</p>
                <h2 id="feedback-title">What people <em>are saying.</em></h2>
            </div>
            <a class="button feedback-leave" href="feedback.php#leave-feedback">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m12 2.8 2.8 5.8 6.3.9-4.6 4.4 1.1 6.3L12 17.2l-5.6 3 1.1-6.3-4.6-4.4 6.3-.9L12 2.8Z"/></svg>
                Feedback
            </a>
        </div>

        <div class="feedback-overview">
            <?= mentora_feedback_summary_card($feedbackSummary) ?>

            <?php if ($homeReviews): ?>
                <div>
                    <div class="review-grid two">
                        <?php foreach ($homeReviews as $review): ?>
                            <?= mentora_feedback_card($review) ?>
                        <?php endforeach; ?>
                    </div>
                    <a class="feedback-all-link" href="feedback.php#reviews">Read all feedback</a>
                </div>
            <?php else: ?>
                <p class="review-empty is-boxed">
                    No reviews yet. <a href="feedback.php#leave-feedback">Be the first to leave feedback.</a>
                </p>
            <?php endif; ?>
        </div>
    </section>

    <section class="cta">
        <div>
            <h2>Make room for what you could become.</h2>
            <p>Explore your pathway and take the first step with Mentora.</p>
        </div>
        <a class="button button-light" href="#pathways">
            Start your application
        </a>
    </section>

    <aside class="support-chat" id="support-chat" aria-labelledby="support-chat-title" hidden>
        <div class="support-chat-header">
            <div class="support-chat-brand">
                <img src="assets/images/mentora-logo.png" alt="">
                <div>
                    <span class="support-chat-status">Mentora</span>
                    <h2 id="support-chat-title">Support</h2>
                </div>
            </div>
            <button class="support-chat-close" type="button" aria-label="Close support information">×</button>
        </div>
        <div class="support-chat-date">Today</div>
        <div class="support-chat-answer" aria-live="polite">
            <div class="support-message support-message-bot">Hi! I can help with Employee VA requirements, internship requirements, applications, training, mentors, and workspaces.</div>
            <div class="support-message support-message-bot">Write a question below and I’ll share the relevant Mentora support information.</div>
        </div>
        <form class="support-chat-form">
            <label class="sr-only" for="support-question">Ask a support question</label>
            <input id="support-question" name="question" type="text" placeholder="Write a message" autocomplete="off" required>
            <button type="submit" aria-label="Send support question">↗</button>
        </form>
    </aside>

    <div class="floating-controls">
        <button class="floating-control back-to-top" type="button" aria-label="Back to top" title="Back to top" hidden>
            <span aria-hidden="true">↑</span>
        </button>
        <button class="floating-control support-chat-toggle"
            type="button"
            aria-label="Open Mentora support"
            title="Open Mentora support"
            aria-expanded="false"
            aria-controls="support-chat">
            <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false">
                <path d="M20 11.5a7.5 7.5 0 0 1-8 7.5 8.8 8.8 0 0 1-3-.5L4 20l1.5-4A7.3 7.3 0 0 1 4.5 12 7.5 7.5 0 0 1 12 4.5a7.5 7.5 0 0 1 8 7Z"/>
                <path d="M8.5 12h.01M12 12h.01M15.5 12h.01"/>
            </svg>
        </button>
    </div>
</main>

<script>
    (() => {
        const chat = document.querySelector('#support-chat');
        const chatToggle = document.querySelector('.support-chat-toggle');
        const chatClose = document.querySelector('.support-chat-close');
        const backToTop = document.querySelector('.back-to-top');
        const answer = document.querySelector('.support-chat-answer');
        const chatForm = document.querySelector('.support-chat-form');
        const questionInput = document.querySelector('#support-question');
        const topics = {
            employee: {
                title: 'Employee VA requirements',
                text: 'Relevant administrative or client support skills, availability for 40 hours per week, and comfort collaborating in English are helpful. Prepare an updated résumé, experience, skills, and availability.'
            },
            intern: {
                title: 'Internship requirements',
                text: 'You should be enrolled in a college or university, meet your school’s requirements, and be able to commit at least 20 hours per week. Keep your school details, required hours, and placement dates ready.'
            },
            mentora: {
                title: 'Mentora support',
                text: 'For questions about applications, training, mentors, or your workspace, email hello@mentora.ph. Please include your name and a short description of what you need help with.'
            }
        };
        const supportResponses = [
            {
                keywords: ['employee', 'va', 'full time', '40 hour', 'resume', 'skill'],
                topic: 'employee'
            },
            {
                keywords: ['intern', 'internship', 'school', 'university', '20 hour', 'placement'],
                topic: 'intern'
            },
            {
                keywords: ['email', 'contact', 'support', 'help', 'application', 'training', 'mentor', 'workspace', 'account'],
                topic: 'mentora'
            }
        ];
        const escapeHtml = (value) => value.replace(/[&<>"']/g, (character) => ({
            '&': '&amp;',
            '<': '&lt;',
            '>': '&gt;',
            '"': '&quot;',
            "'": '&#039;'
        }[character]));
        const appendMessage = (message, type) => {
            answer.insertAdjacentHTML('beforeend', `<div class="support-message support-message-${type}">${message}</div>`);
            answer.scrollTop = answer.scrollHeight;
        };
        const renderResponse = (topic, question = '') => {
            if (question) {
                appendMessage(escapeHtml(question), 'user');
            }
            appendMessage(`<strong>${topic.title}</strong><br>${topic.text}`, 'bot');
        };
        const unknownResponse = (question) => {
            appendMessage(escapeHtml(question), 'user');
            appendMessage('<strong>Let us help you directly</strong><br>I can answer questions about employee VA requirements, internships, applications, training, mentors, and workspaces. For other questions, email <a href="mailto:hello@mentora.ph">hello@mentora.ph</a>.', 'bot');
        };

        const setChatVisibility = (isOpen) => {
            chat.hidden = !isOpen;
            chatToggle.setAttribute('aria-expanded', String(isOpen));
            chatToggle.setAttribute('aria-label', isOpen ? 'Close Mentora support' : 'Open Mentora support');
            chatToggle.setAttribute('title', isOpen ? 'Close Mentora support' : 'Open Mentora support');
        };

        chatToggle.addEventListener('click', () => {
            setChatVisibility(chat.hidden);
        });

        chatClose.addEventListener('click', () => {
            setChatVisibility(false);
            chatToggle.focus();
        });

        chatForm.addEventListener('submit', (event) => {
            event.preventDefault();
            const userQuestion = questionInput.value.trim();
            const question = userQuestion.toLowerCase();
            const response = supportResponses.find(({ keywords }) =>
                keywords.some((keyword) => question.includes(keyword))
            );

            if (response) {
                renderResponse(topics[response.topic], userQuestion);
            } else {
                unknownResponse(userQuestion);
            }

            questionInput.value = '';
        });

        const updateBackToTopVisibility = () => {
            backToTop.hidden = window.scrollY < 320;
        };

        window.addEventListener('scroll', updateBackToTopVisibility, { passive: true });
        updateBackToTopVisibility();

        backToTop.addEventListener('click', () => {
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });

        // About us preview: clicking anywhere on the section opens the About us page.
        // (Done in script rather than with an invisible overlay, so the text can have its own hover effects.)
        const aboutTeaser = document.querySelector('.about-teaser');
        const aboutLink = aboutTeaser?.querySelector('.about-teaser-link');
        if (aboutTeaser && aboutLink) {
            aboutTeaser.addEventListener('click', (event) => {
                if (event.target.closest('a') || String(window.getSelection()).trim() !== '') {
                    return; // real link clicks and text selection behave normally
                }
                if (event.ctrlKey || event.metaKey) {
                    window.open(aboutLink.href, '_blank');
                } else {
                    window.location.href = aboutLink.href;
                }
            });
        }
    })();
</script>

<?php
require __DIR__ . '/includes/footer.php';
?>