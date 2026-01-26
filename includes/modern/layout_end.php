            </div><!-- /.page-content -->
        </main>
    </div>

    <!-- Bootstrap 5 JS Bundle (for dropdowns, modals) -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <?php if (!empty($extraJs)): ?>
        <?php foreach ($extraJs as $js): ?>
        <script src="<?= e($js) ?>"></script>
        <?php endforeach; ?>
    <?php endif; ?>

    <script>
    // Auto-hide flash messages
    setTimeout(function() {
        const flash = document.getElementById('flashMessage');
        if (flash) {
            flash.style.opacity = '0';
            flash.style.transform = 'translateX(100%)';
            flash.style.transition = 'all 0.3s ease';
            setTimeout(() => flash.remove(), 300);
        }
    }, 5000);

    // Sidebar toggle persistence
    const sidebar = document.getElementById('sidebar');
    if (localStorage.getItem('sidebarCollapsed') === 'true') {
        sidebar.classList.add('collapsed');
    }
    document.querySelector('.header-toggle')?.addEventListener('click', function() {
        sidebar.classList.toggle('collapsed');
        localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
    });
    </script>
</body>
</html>
