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

    // Sidebar toggle: collapse on desktop, slide-in on mobile
    const sidebar = document.getElementById('sidebar');
    const sidebarBackdrop = (() => {
        const el = document.createElement('div');
        el.className = 'sidebar-backdrop';
        document.body.appendChild(el);
        return el;
    })();
    const isMobileViewport = () => window.matchMedia('(max-width: 1024px)').matches;

    function setMobileOpen(open) {
        if (!sidebar) return;
        sidebar.classList.toggle('open', open);
        sidebarBackdrop.classList.toggle('show', open);
    }

    function syncSidebarState() {
        if (!sidebar) return;
        if (isMobileViewport()) {
            sidebar.classList.remove('collapsed');
            setMobileOpen(false);
        } else if (localStorage.getItem('sidebarCollapsed') === 'true') {
            sidebar.classList.add('collapsed');
            setMobileOpen(false);
        } else {
            sidebar.classList.remove('collapsed');
            setMobileOpen(false);
        }
    }

    syncSidebarState();
    window.addEventListener('resize', syncSidebarState);

    document.addEventListener('click', function(event) {
        const toggleBtn = event.target.closest('.header-toggle');
        if (!toggleBtn || !sidebar) return;
        event.preventDefault();
        if (isMobileViewport()) {
            setMobileOpen(!sidebar.classList.contains('open'));
        } else {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('sidebarCollapsed', sidebar.classList.contains('collapsed'));
        }
    });

    sidebarBackdrop.addEventListener('click', () => setMobileOpen(false));
    sidebar?.addEventListener('click', (e) => {
        if (isMobileViewport() && e.target.closest('.nav-item')) {
            setMobileOpen(false);
        }
    });
    </script>
</body>
</html>
