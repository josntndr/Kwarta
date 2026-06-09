    </main>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <?php
        $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
        $assetPrefix = str_contains($scriptName, '/admin/') ? '../' : '';
    ?>
    <script src="<?= e($assetPrefix) ?>assets/js/app.js"></script>
    <?php if (is_logged_in() && !keep_logged_in()): ?>
        <script>
        (function () {
            const marker = 'kwarta_tab_session_active';
            const params = new URLSearchParams(window.location.search);
            if (params.get('fresh') === '1') {
                sessionStorage.setItem(marker, '1');
                params.delete('fresh');
                const query = params.toString();
                window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
                return;
            }
            if (!sessionStorage.getItem(marker)) {
                window.location.replace('<?= e($assetPrefix) ?>logout.php?expired=1');
            }
        })();
        </script>
    <?php endif; ?>
</body>
</html>
