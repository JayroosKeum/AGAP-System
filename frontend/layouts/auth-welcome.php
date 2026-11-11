<?php
$authWelcome = is_array($authWelcome ?? null) ? $authWelcome : [];
$awEyebrow = (string) ($authWelcome['eyebrow'] ?? 'Katarungang Pambarangay · Marikina City');
$awTitleId = (string) ($authWelcome['title_id'] ?? 'auth-welcome-title');
$awTitleHtml = (string) ($authWelcome['title_html'] ?? 'Good work starts with a <span>clear process.</span>');
$awSubtitle = (string) ($authWelcome['subtitle'] ?? '');
$awSubtitleId = (string) ($authWelcome['subtitle_id'] ?? '');
$awFooter = (string) ($authWelcome['footer_text'] ?? 'Supporting fair and orderly community resolution.');
?>
<section class="login-welcome" aria-labelledby="<?php echo htmlspecialchars($awTitleId, ENT_QUOTES, 'UTF-8'); ?>">
    <a class="login-brand" href="../landing/index.php" aria-label="AGAP home">
        <img src="../../assets/images/tumana_logo.jpg" alt="Barangay Tumana seal">
        <span><strong>AGAP</strong><small>Barangay Tumana</small></span>
    </a>

    <div class="welcome-copy">
        <p class="login-eyebrow"><?php echo htmlspecialchars($awEyebrow, ENT_QUOTES, 'UTF-8'); ?></p>
        <h1 id="<?php echo htmlspecialchars($awTitleId, ENT_QUOTES, 'UTF-8'); ?>"><?php echo $awTitleHtml; ?></h1>
        <?php if ($awSubtitle !== ''): ?>
            <p<?php echo $awSubtitleId !== '' ? ' id="' . htmlspecialchars($awSubtitleId, ENT_QUOTES, 'UTF-8') . '"' : ''; ?>><?php echo htmlspecialchars($awSubtitle, ENT_QUOTES, 'UTF-8'); ?></p>
        <?php endif; ?>
    </div>

    <div class="welcome-foot">
        <img src="../../assets/images/marikina_logo.jpg" alt="City of Marikina seal">
        <span><?php echo htmlspecialchars($awFooter, ENT_QUOTES, 'UTF-8'); ?></span>
    </div>
    <div class="login-decoration login-decoration-one" aria-hidden="true"></div>
    <div class="login-decoration login-decoration-two" aria-hidden="true"></div>
</section>
