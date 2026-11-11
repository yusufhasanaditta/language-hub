<?php
defined('B_ACADEMY') or die('Direct access forbidden.');
/**
 * Shared hero + layout CSS for the legal/policy pages (refund.php,
 * privacy.php, terms.php). Caller sets $page_title and $lastUpdated
 * before including this, right after includes/header.php.
 */
?>
<section class="section-sm" style="background:linear-gradient(135deg,#1A1033,#2A0E4A);padding:var(--sp-14) 0">
    <div class="container" style="text-align:center;color:#fff">
        <div class="section-label" style="background:rgba(255,255,255,.12);color:rgba(255,255,255,.9)">Legal</div>
        <h1 style="font-size:clamp(1.6rem,4vw,2.6rem);color:#fff;margin:var(--sp-3) 0"><?= e($page_title) ?></h1>
        <?php if (!empty($lastUpdated)): ?>
        <p style="color:rgba(255,255,255,.6);font-size:var(--text-sm)">Last updated: <?= e($lastUpdated) ?></p>
        <?php endif; ?>
    </div>
</section>

<style>
.legal-layout { display: grid; grid-template-columns: 260px 1fr; gap: var(--sp-10); align-items: start; }
.legal-toc { position: sticky; top: calc(var(--sp-4) + 72px); background: var(--c-surface); border: 1px solid var(--c-border); border-radius: var(--r-lg); padding: var(--sp-5); }
.legal-toc-title { font-size: var(--text-xs); font-weight: 800; text-transform: uppercase; letter-spacing: .06em; color: var(--c-ink-muted); margin-bottom: var(--sp-3); }
.legal-toc a { display: block; padding: 6px 0; font-size: var(--text-sm); color: var(--c-ink-light); text-decoration: none; border-left: 2px solid transparent; padding-left: 10px; margin-left: -12px; }
.legal-toc a:hover { color: var(--c-primary); border-left-color: var(--c-primary); }
.legal-content { max-width: 760px; }
.legal-content .legal-lead { font-size: var(--text-lg); color: var(--c-ink-light); line-height: 1.8; margin-bottom: var(--sp-8); }
.legal-content h2 { font-size: var(--text-xl); margin: var(--sp-10) 0 var(--sp-4); scroll-margin-top: 90px; }
.legal-content h2:first-of-type { margin-top: 0; }
.legal-content p, .legal-content li { color: var(--c-ink-light); line-height: 1.8; font-size: var(--text-base); }
.legal-content ul, .legal-content ol { padding-left: 1.4em; margin: var(--sp-3) 0 var(--sp-5); display: flex; flex-direction: column; gap: 8px; }
.legal-content a { color: var(--c-primary); font-weight: 600; }
.legal-content strong { color: var(--c-ink); }
@media (max-width: 900px) {
    .legal-layout { grid-template-columns: 1fr !important; }
    .legal-toc { position: static; margin-bottom: var(--sp-6); }
}
</style>
