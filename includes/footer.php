<script src="<?= e(asset('js/api.js')) ?>"></script>
<script src="<?= e(asset('js/spinner.js')) ?>"></script>
<?php foreach (($scripts ?? []) as $s): ?>
<script src="<?= e(asset('js/' . $s)) ?>"></script>
<?php endforeach; ?>
</body></html>
