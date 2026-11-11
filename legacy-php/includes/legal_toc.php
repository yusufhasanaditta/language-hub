<?php
defined('B_ACADEMY') or die('Direct access forbidden.');
/**
 * Sticky table-of-contents sidebar for the legal/policy pages. Caller sets
 * $sections as ['anchor-id' => 'Label', ...] before including this.
 */
?>
<aside class="legal-toc">
    <div class="legal-toc-title">On This Page</div>
    <nav>
        <?php foreach ($sections ?? [] as $anchor => $label): ?>
        <a href="#<?= e($anchor) ?>"><?= e($label) ?></a>
        <?php endforeach; ?>
    </nav>
</aside>
