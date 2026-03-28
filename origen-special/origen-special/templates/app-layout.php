<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=0">
    <title>Origen SPECIAL - Plataforma</title>
    <?php wp_head(); ?>
</head>
<body class="origen-app-body">

    <div class="origen-app-wrapper">
        <header class="origen-app-header">
            <h1>Origen SPECIAL</h1>
            <p>Plataforma Agro</p>
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