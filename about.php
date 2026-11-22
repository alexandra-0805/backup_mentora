<?php
/**
 * About us: public page, linked from the "About us" band on the landing page.
 * Reuses the landing page's look (landing-page body class) and shared icons.
 */
require_once __DIR__ . '/includes/icons.php';

$pageTitle = 'About us | Mentora';
$bodyClass = 'landing-page about-page';
$headerStyle = 'landing-page'; // same top bar as the landing page

require __DIR__ . '/includes/header.php';
?>

<main>
    <section class="hero about-hero">
        <div class="hero-content">
            <p class="eyebrow">About us</p>
            <h1>Growing people,<br><em>together.</em></h1>
            <p class="hero-copy">
                Mentora brings recruitment, onboarding, training, and development into one
                thoughtful workspace, so applicants, mentors, and people teams can focus on progress.
            </p>
            <div class="actions">
                <a class="button button-primary" href="#story">Our story</a>
                <a class="button button-ghost" href="auth/signup.php">Apply now</a>
            </div>
        </div>
    </section>

    <section id="story" class="section" aria-labelledby="story-title">
        <div class="section-heading">
            <div>
                <h2 id="story-title">Why Mentora<br>exists.</h2>
            </div>
            <p class="section-intro">
                Starting a new role or internship often means juggling forms, emails, and
                spreadsheets. We built Mentora so every step, from your first application to your
                latest evaluation, lives in one place you can trust.
            </p>
        </div>
        <div class="card-grid two">
            <article class="feature-card about-card">
                <div class="card-topline">
                    <span class="icon"><?= mentora_icon('target') ?></span>
                    <span class="tag">Our mission</span>
                </div>
                <h3>Make every first step count</h3>
                <p>
                    Give every applicant a clear, supported path into meaningful work, with the
                    training, mentoring, and feedback they need to grow with confidence.
                </p>
            </article>
            <article class="feature-card about-card">
                <div class="card-topline">
                    <span class="icon"><?= mentora_icon('sprout') ?></span>
                    <span class="tag">Our vision</span>
                </div>
                <h3>A workplace where people grow together</h3>
                <p>
                    A connected community where interns, employees, mentors, and people teams
                    learn from each other and every contribution is seen.
                </p>
            </article>
        </div>
    </section>

    <section class="section pale" aria-labelledby="does-title">
        <div class="section-heading">
            <div>
                <h2 id="does-title">What Mentora<br>does.</h2>
            </div>
            <p class="section-intro">One workspace for the whole journey, from applying to growing in your role.</p>
        </div>
        <div class="card-grid three">
            <article class="mini-card">
                <span class="icon"><?= mentora_icon('document') ?></span>
                <h3>Simple applications</h3>
                <p>Apply as an Employee VA or an intern with one form, and go straight to your own workspace.</p>
            </article>
            <article class="mini-card">
                <span class="icon"><?= mentora_icon('book') ?></span>
                <h3>Guided training</h3>
                <p>Follow a structured learning plan and track your modules, hours, and progress.</p>
            </article>
            <article class="mini-card">
                <span class="icon"><?= mentora_icon('check-circle') ?></span>
                <h3>Mentoring &amp; evaluation</h3>
                <p>Work with an assigned agent and understand your growth through clear, fair reviews.</p>
            </article>
        </div>
    </section>

    <section class="section" aria-labelledby="serve-title">
        <div class="section-heading">
            <div>
                <h2 id="serve-title">Who we<br>work with.</h2>
            </div>
            <p class="section-intro">Each person gets a workspace built around what they need to do next.</p>
        </div>
        <div class="card-grid three">
            <article class="mini-card about-light-card">
                <span class="icon"><?= mentora_icon('graduation') ?></span>
                <h3>Applicants</h3>
                <p>Employee VAs and interns follow their application, training, evaluations, and reports.</p>
            </article>
            <article class="mini-card about-light-card">
                <span class="icon"><?= mentora_icon('users') ?></span>
                <h3>Agents &amp; mentors</h3>
                <p>Guide your team, track training, and give feedback through individual evaluations.</p>
            </article>
            <article class="mini-card about-light-card">
                <span class="icon"><?= mentora_icon('briefcase') ?></span>
                <h3>HR &amp; people teams</h3>
                <p>See people across departments, monitor progress, and manage secure, role-based access.</p>
            </article>
        </div>
    </section>

    <?php
    // Photo carousel. To add a photo: put it in assets/images/about/ and add a line here.
    $gallery = [
        [
            'src' => 'assets/images/about/online-internship-session.jpg',
            'alt' => 'Participants on a Google Meet video call for the Mentora PH VA Students Internship Program',
            'title' => 'VA Students Internship Program',
            'caption' => 'Interns and mentors learning together in an online session.',
        ],
        [
            'src' => 'assets/images/about/community-meetup.jpg',
            'alt' => 'Members of the Mentora community posing together for a group photo at a café',
            'title' => 'Mentora community meetup',
            'caption' => 'Our community getting together in person.',
        ],
        [
            'src' => 'assets/images/about/coffee-with-interns.jpg',
            'alt' => 'Four students in school uniforms sitting at a café table with a Mentora team member and a laptop',
            'title' => 'Coffee with our interns',
            'caption' => 'Catching up with student interns over coffee.',
        ],
    ];
    $slideCount = count($gallery);
    ?>
    <section class="section about-gallery" aria-labelledby="gallery-title">
        <div class="section-heading">
            <div>
                <h2 id="gallery-title">Moments from<br>our community.</h2>
            </div>
            <p class="section-intro">A look at the people learning, mentoring, and growing with Mentora.</p>
        </div>

        <div class="carousel" data-carousel aria-roledescription="carousel" aria-label="Mentora community photos">
            <div class="carousel-viewport" aria-live="off">
                <ul class="carousel-track">
                    <?php foreach ($gallery as $i => $slide): ?>
                        <li class="carousel-slide" role="group" aria-roledescription="slide" aria-label="<?= $i + 1 ?> of <?= $slideCount ?>">
                            <img src="<?= htmlspecialchars($slide['src'], ENT_QUOTES, 'UTF-8') ?>"
                                 alt="<?= htmlspecialchars($slide['alt'], ENT_QUOTES, 'UTF-8') ?>"
                                 loading="<?= $i === 0 ? 'eager' : 'lazy' ?>">
                            <div class="carousel-caption">
                                <strong><?= htmlspecialchars($slide['title'], ENT_QUOTES, 'UTF-8') ?></strong>
                                <span><?= htmlspecialchars($slide['caption'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>

            <button class="carousel-btn carousel-prev" type="button" aria-label="Previous photo">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m15 5-7 7 7 7"/></svg>
            </button>
            <button class="carousel-btn carousel-next" type="button" aria-label="Next photo">
                <svg viewBox="0 0 24 24" aria-hidden="true" focusable="false"><path d="m9 5 7 7-7 7"/></svg>
            </button>

            <div class="carousel-controls">
                <div class="carousel-dots">
                    <?php for ($i = 0; $i < $slideCount; $i++): ?>
                        <button class="carousel-dot" type="button" aria-label="Show photo <?= $i + 1 ?>"></button>
                    <?php endfor; ?>
                </div>
            </div>
        </div>
    </section>

    <section class="section preparation" aria-labelledby="values-title">
        <div class="preparation-copy">
            <h2 id="values-title">What we<br>value.</h2>
            <p>The ideas that shape how Mentora works for you.</p>
        </div>
        <div class="faq-list" aria-label="Frequently asked questions">
            <details open>
                <summary>Which pathway should I choose?</summary>
                <p>Choose employee VA if you’re ready to bring relevant skills to a full-time role. Choose internship if you need supervised, school-accredited work experience.</p>
            </details>
            <details>
                <summary>What are the internship requirements?</summary>
                <p>You should be enrolled in a college or university, meet your school’s requirements, and be able to commit at least 20 hours per week.</p>
            </details>
            <details>
                <summary>Can I save my application and return later?</summary>
                <p>Yes. Create an account and sign in to your workspace to continue managing your application and documents.</p>
            </details>
        </div>
    </section>

    <section class="cta">
        <div>
            <h2>Ready to start your journey?</h2>
            <p>Questions? Email <a href="mailto:hello@mentora.ph">hello@mentora.ph</a>, or apply today and we’ll guide you from there.</p>
        </div>
        <a class="button button-light" href="auth/signup.php">
            Apply now
        </a>
    </section>
</main>

<script>
    // Automatic photo carousel: moves to the next photo every 5 seconds and loops forever.
    // It keeps playing while you point at it; the active dot fills up to show when the next photo comes.
    // Arrows, dots, ← → keys and swiping jump to a photo and the slideshow carries on from there.
    // It only pauses while someone tabs through it with the keyboard and while the browser tab is hidden.
    (() => {
        const DELAY = 5000; // milliseconds per photo

        document.querySelectorAll('[data-carousel]').forEach((carousel) => {
            const viewport = carousel.querySelector('.carousel-viewport');
            const track = carousel.querySelector('.carousel-track');
            const slides = [...carousel.querySelectorAll('.carousel-slide')];
            const dots = [...carousel.querySelectorAll('.carousel-dot')];
            let index = 0;
            let timer = null;
            let keyboardFocus = false; // someone is tabbing through the carousel

            carousel.style.setProperty('--carousel-delay', `${DELAY}ms`);

            const show = (next) => {
                index = (next + slides.length) % slides.length;
                track.style.transform = `translateX(-${index * 100}%)`;
                slides.forEach((slide, n) => {
                    slide.setAttribute('aria-hidden', String(n !== index));
                    slide.inert = n !== index;
                });
                dots.forEach((dot, n) => dot.setAttribute('aria-current', n === index ? 'true' : 'false'));
            };
            const running = () => !keyboardFocus && !document.hidden;
            const schedule = () => {
                clearTimeout(timer);
                viewport.setAttribute('aria-live', running() ? 'off' : 'polite');
                // Restart the dot's progress fill.
                carousel.classList.remove('is-running');
                void carousel.offsetWidth;
                if (running()) {
                    carousel.classList.add('is-running');
                    timer = setTimeout(() => { show(index + 1); schedule(); }, DELAY);
                }
            };
            const goTo = (next) => { show(next); schedule(); };

            carousel.querySelector('.carousel-prev').addEventListener('click', () => goTo(index - 1));
            carousel.querySelector('.carousel-next').addEventListener('click', () => goTo(index + 1));
            dots.forEach((dot, n) => dot.addEventListener('click', () => goTo(n)));

            // Pause only for keyboard focus (Tab), not after a mouse click on an arrow or dot.
            carousel.addEventListener('focusin', (event) => {
                if (event.target.matches(':focus-visible')) { keyboardFocus = true; schedule(); }
            });
            carousel.addEventListener('focusout', (event) => {
                if (!carousel.contains(event.relatedTarget)) { keyboardFocus = false; schedule(); }
            });
            carousel.addEventListener('keydown', (event) => {
                if (event.key === 'ArrowLeft') { goTo(index - 1); }
                if (event.key === 'ArrowRight') { goTo(index + 1); }
            });
            document.addEventListener('visibilitychange', schedule);

            let startX = null;
            viewport.addEventListener('pointerdown', (event) => { startX = event.clientX; });
            viewport.addEventListener('pointerup', (event) => {
                if (startX === null) { return; }
                const distance = event.clientX - startX;
                startX = null;
                if (Math.abs(distance) > 40) { goTo(index + (distance < 0 ? 1 : -1)); }
            });

            show(0);
            schedule();
        });
    })();
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
