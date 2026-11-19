<?php
// Mobile menu (hamburger) button for the workspace sidebar (admin, agent, applicant).
// Included inside the sidebar <aside>, right after the logo. Hidden above 760px (see assets/css/admin.css).
?>
<button class="sidebar-toggle" type="button" aria-label="Open menu" aria-expanded="false" aria-controls="workspace-nav">
    <span aria-hidden="true"></span>
    <span aria-hidden="true"></span>
    <span aria-hidden="true"></span>
</button>
<script>
    (() => {
        const toggle = document.currentScript.previousElementSibling;
        const sidebar = toggle ? toggle.closest('aside') : null;

        if (!sidebar) {
            return;
        }

        const isOpen = () => sidebar.classList.contains('is-open');

        const setOpen = (open) => {
            if (open) {
                // The bar leaves the page flow while it floats as a drawer; keep its space so nothing jumps.
                document.body.style.setProperty('--sidebar-bar-height', `${sidebar.offsetHeight}px`);
            }
            sidebar.classList.toggle('is-open', open);
            document.body.classList.toggle('sidebar-menu-open', open);
            toggle.setAttribute('aria-expanded', String(open));
            toggle.setAttribute('aria-label', open ? 'Close menu' : 'Open menu');
        };

        toggle.addEventListener('click', () => {
            setOpen(!isOpen());
        });

        // Tapping the dimmed backdrop (anywhere outside the drawer) closes it.
        document.addEventListener('click', (event) => {
            if (isOpen() && !sidebar.contains(event.target)) {
                setOpen(false);
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && isOpen()) {
                setOpen(false);
                toggle.focus();
            }
        });

        // Close if the screen grows back to the desktop sidebar.
        window.matchMedia('(max-width: 760px)').addEventListener('change', () => setOpen(false));
    })();
</script>
