<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, interactive-widget=resizes-content">
    <title>Origen SPECIAL - Plataforma Premium</title>

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <script src="https://unpkg.com/@phosphor-icons/web"></script>

    <?php wp_head(); ?>
</head>
<body class="origen-app-body">

    <div class="origen-app-wrapper">
        <header class="origen-app-header">
            <div class="header-logo-icon">
                <i class="ph-fill ph-leaf"></i>
            </div>
            <h1>Origen <span>SPECIAL</span></h1>
            <p>Agro Platform</p>
        </header>

        <main class="origen-app-content">
            <?php
            if ( is_user_logged_in() ) {
                echo do_shortcode( '[origen_special_dashboard]' );
            } else {
                echo do_shortcode( '[origen_special_register]' );
            }
            ?>
        </main>
    </div>

    <?php wp_footer(); ?>
</body>
</html>
