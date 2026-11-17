<script>
    (() => {
        const theme = window.localStorage.getItem('mentora-workspace-theme');
        document.body.classList.toggle('workspace-dark-mode', theme === 'dark');
    })();
</script>
