    </div><!-- /.container-fluid -->
</main>

<footer class="footer mt-auto py-3 bg-light">
    <div class="container-fluid">
        <div class="d-flex justify-content-between align-items-center">
            <span class="text-muted">
                <?= APP_NAME ?? 'ERP v2' ?> &copy; <?= date('Y') ?>
            </span>
            <span class="text-muted small">
                Version <?= APP_VERSION ?? '2.0.0' ?>
            </span>
        </div>
    </div>
</footer>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<!-- Custom JS -->
<script src="<?= BASE_URL ?>/assets/js/main.js"></script>

<?php if (!empty($extraJs)): ?>
    <?php foreach ($extraJs as $js): ?>
        <script src="<?= e($js) ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

</body>
</html>
