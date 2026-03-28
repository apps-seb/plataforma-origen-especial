<?php
/**
 * Plugin Name: Origen SPECIAL Agro Platform
 * Description: Plataforma tipo app para caficultores.
 * Version: 1.0.0
 * Author: Tu Nombre
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// 1. ACTIVACIÓN DEL PLUGIN
register_activation_hook( __FILE__, 'origen_special_activate' );
function origen_special_activate() {
    // Crear rol si no existe
    if ( ! get_role( 'caficultor' ) ) {
        add_role( 'caficultor', 'Caficultor', array( 'read' => true ) );
    }

    // Crear página /caficultores si no existe
    $page = get_page_by_path( 'caficultores' );
    if ( ! $page ) {
        wp_insert_post( array(
            'post_title'   => 'Caficultores',
            'post_name'    => 'caficultores',
            'post_status'  => 'publish',
            'post_type'    => 'page',
        ) );
    }

    // Crear tabla wp_origen_fincas
    global $wpdb;
    $table_name = $wpdb->prefix . 'origen_fincas';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        nombre_finca varchar(255) NOT NULL,
        ubicacion varchar(255) NOT NULL,
        altura int(11) NOT NULL,
        hectareas decimal(10,2) NOT NULL,
        cantidad_plantas int(11) NOT NULL,
        variedad_cafe varchar(100) NOT NULL,
        edad_cultivo int(11) NOT NULL,
        densidad_siembra int(11) NOT NULL,
        tipo_sombra varchar(10) NOT NULL,
        sistema_cultivo varchar(50) NOT NULL,
        fecha_registro datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        PRIMARY KEY  (id),
        UNIQUE KEY user_id (user_id)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
}

// 2. ENQUEUE SCRIPTS & STYLES
add_action( 'wp_enqueue_scripts', 'origen_special_assets' );
function origen_special_assets() {
    if ( is_page( 'caficultores' ) ) {
        wp_enqueue_style( 'origen-style', plugin_dir_url( __FILE__ ) . 'assets/css/style.css', array(), '1.0.0' );
        wp_enqueue_script( 'origen-js', plugin_dir_url( __FILE__ ) . 'assets/js/app.js', array(), '1.0.0', true );
        wp_localize_script( 'origen-js', 'origenApp', array(
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'nonce'    => wp_create_nonce( 'origen_auth_nonce' )
        ) );
    }
}

// 3. INTERCEPTAR PLANTILLA
add_filter( 'template_include', 'origen_special_template' );
function origen_special_template( $template ) {
    if ( is_page( 'caficultores' ) ) {
        $plugin_template = plugin_dir_path( __FILE__ ) . 'templates/app-layout.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
    }
    return $template;
}

// 4. SHORTCODE: REGISTRO/LOGIN
add_shortcode( 'origen_special_register', 'origen_special_register_html' );
function origen_special_register_html() {
    ob_start(); ?>
    <div class="origen-auth-box">
        <div class="origen-tabs">
            <button class="origen-tab-btn active" data-target="login">Iniciar Sesión</button>
            <button class="origen-tab-btn" data-target="register">Registrarse</button>
        </div>

        <form id="origen-login-form" class="origen-form active">
            <div class="origen-input-group">
                <label for="log_user">Email o Usuario</label>
                <input type="text" id="log_user" required>
            </div>
            <div class="origen-input-group">
                <label for="log_pass">Contraseña</label>
                <input type="password" id="log_pass" required>
            </div>
            <button type="submit" class="origen-btn">Entrar</button>
        </form>

        <form id="origen-register-form" class="origen-form" style="display: none;">
            <div class="origen-input-group">
                <label for="reg_name">Nombre Completo</label>
                <input type="text" id="reg_name" required>
            </div>
            <div class="origen-input-group">
                <label for="reg_email">Email</label>
                <input type="email" id="reg_email" required>
            </div>
            <div class="origen-input-group">
                <label for="reg_pass">Contraseña</label>
                <input type="password" id="reg_pass" required>
            </div>
            <button type="submit" class="origen-btn">Crear Cuenta</button>
        </form>

        <div id="origen-msg" class="origen-msg"></div>
    </div>
    <?php
    return ob_get_clean();
}

// 5. SHORTCODE: DASHBOARD
add_shortcode( 'origen_special_dashboard', 'origen_special_dashboard_html' );
function origen_special_dashboard_html() {
    $current_user = wp_get_current_user();
    ob_start(); ?>
    <div class="origen-dashboard" id="origen-dashboard-container">
        <h2>Bienvenido, <?php echo esc_html( $current_user->display_name ); ?> 👋</h2>
        <div class="origen-grid">
            <button id="origen-btn-mi-finca" class="origen-card-btn">🌱 Mi Finca</button>
            <button class="origen-card-btn">📊 Calcular Producción</button>
            <button class="origen-card-btn">🛒 Ver Tienda</button>
            <a href="<?php echo esc_url( wp_logout_url( site_url( '/caficultores' ) ) ); ?>" class="origen-btn-outline">Cerrar Sesión</a>
        </div>
    </div>
    <?php echo do_shortcode('[origen_special_finca]'); ?>
    <?php
    return ob_get_clean();
}

// 6. SHORTCODE: PERFIL DE FINCA
add_shortcode( 'origen_special_finca', 'origen_special_finca_html' );
function origen_special_finca_html() {
    if ( ! is_user_logged_in() ) {
        return '<p>Debes iniciar sesión para ver esta información.</p>';
    }

    $current_user = wp_get_current_user();

    global $wpdb;
    $table_name = $wpdb->prefix . 'origen_fincas';
    $finca = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE user_id = %d", $current_user->ID ) );

    $nombre_finca = $finca ? esc_attr( $finca->nombre_finca ) : '';
    $ubicacion = $finca ? esc_attr( $finca->ubicacion ) : '';
    $altura = $finca ? esc_attr( $finca->altura ) : '';
    $hectareas = $finca ? esc_attr( $finca->hectareas ) : '';
    $cantidad_plantas = $finca ? esc_attr( $finca->cantidad_plantas ) : '';
    $variedad_cafe = $finca ? esc_attr( $finca->variedad_cafe ) : '';
    $edad_cultivo = $finca ? esc_attr( $finca->edad_cultivo ) : '';
    $densidad_siembra = $finca ? esc_attr( $finca->densidad_siembra ) : '';
    $tipo_sombra = $finca ? esc_attr( $finca->tipo_sombra ) : '';
    $sistema_cultivo = $finca ? esc_attr( $finca->sistema_cultivo ) : '';

    $variedades = array('Castillo', 'Caturra', 'Colombia', 'Typica', 'Bourbon');

    ob_start(); ?>
    <div id="origen-finca-container" class="origen-finca-container" style="display: none;">
        <button id="origen-btn-volver" class="origen-btn-outline" style="margin-bottom: 20px; margin-top: 0; padding: 10px; width: auto;">🔙 Volver al Dashboard</button>
        <h3>🌱 <?php echo $finca ? 'Editar Mi Finca' : 'Registrar Mi Finca'; ?></h3>

        <form id="origen-finca-form" class="origen-form active">
            <div class="origen-input-group">
                <label for="finca_nombre">Nombre de la finca</label>
                <input type="text" id="finca_nombre" value="<?php echo $nombre_finca; ?>" required>
            </div>
            <div class="origen-input-group">
                <label for="finca_ubicacion">Ubicación (Departamento/Municipio)</label>
                <input type="text" id="finca_ubicacion" value="<?php echo $ubicacion; ?>" required>
            </div>
            <div class="origen-input-group">
                <label for="finca_altura">Altura (msnm)</label>
                <input type="number" id="finca_altura" value="<?php echo $altura; ?>" required>
            </div>
            <div class="origen-input-group">
                <label for="finca_hectareas">Hectáreas</label>
                <input type="number" step="0.01" id="finca_hectareas" value="<?php echo $hectareas; ?>" required>
            </div>
            <div class="origen-input-group">
                <label for="finca_plantas">Cantidad de plantas</label>
                <input type="number" id="finca_plantas" value="<?php echo $cantidad_plantas; ?>" required>
            </div>

            <div class="origen-input-group">
                <label for="finca_variedad">Variedad de café</label>
                <select id="finca_variedad" required>
                    <option value="" disabled <?php echo empty($variedad_cafe) ? 'selected' : ''; ?>>Seleccione una variedad</option>
                    <?php foreach ($variedades as $var): ?>
                        <option value="<?php echo esc_attr($var); ?>" <?php selected($variedad_cafe, $var); ?>><?php echo esc_html($var); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="origen-input-group">
                <label for="finca_edad">Edad del cultivo (años)</label>
                <input type="number" id="finca_edad" value="<?php echo $edad_cultivo; ?>" required>
            </div>
            <div class="origen-input-group">
                <label for="finca_densidad">Densidad de siembra</label>
                <input type="number" id="finca_densidad" value="<?php echo $densidad_siembra; ?>" required>
            </div>

            <div class="origen-input-group">
                <label>Tipo de sombra</label>
                <div class="origen-radio-group">
                    <label><input type="radio" name="finca_sombra" value="si" <?php checked($tipo_sombra, 'si'); echo empty($tipo_sombra) ? 'checked' : ''; ?>> Sí</label>
                    <label><input type="radio" name="finca_sombra" value="no" <?php checked($tipo_sombra, 'no'); ?>> No</label>
                </div>
            </div>

            <div class="origen-input-group">
                <label>Sistema de cultivo</label>
                <div class="origen-radio-group">
                    <label><input type="radio" name="finca_sistema" value="tradicional" <?php checked($sistema_cultivo, 'tradicional'); echo empty($sistema_cultivo) ? 'checked' : ''; ?>> Tradicional</label>
                    <label><input type="radio" name="finca_sistema" value="tecnificado" <?php checked($sistema_cultivo, 'tecnificado'); ?>> Tecnificado</label>
                </div>
            </div>

            <button type="submit" class="origen-btn">Guardar Finca</button>
        </form>
        <div id="origen-finca-msg" class="origen-msg"></div>
    </div>
    <?php
    return ob_get_clean();
}

// 7. LÓGICA AJAX: GUARDAR FINCA
add_action( 'wp_ajax_origen_save_finca', 'origen_ajax_save_finca' );
function origen_ajax_save_finca() {
    check_ajax_referer( 'origen_auth_nonce', 'nonce' );

    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Acceso denegado.' );
    }

    $current_user = wp_get_current_user();

    // Sanitizar inputs
    $nombre_finca = sanitize_text_field( $_POST['nombre_finca'] );
    $ubicacion = sanitize_text_field( $_POST['ubicacion'] );
    $altura = intval( $_POST['altura'] );
    $hectareas = floatval( $_POST['hectareas'] );
    $cantidad_plantas = intval( $_POST['cantidad_plantas'] );
    $variedad_cafe = sanitize_text_field( $_POST['variedad_cafe'] );
    $edad_cultivo = intval( $_POST['edad_cultivo'] );
    $densidad_siembra = intval( $_POST['densidad_siembra'] );
    $tipo_sombra = sanitize_text_field( $_POST['tipo_sombra'] );
    $sistema_cultivo = sanitize_text_field( $_POST['sistema_cultivo'] );

    global $wpdb;
    $table_name = $wpdb->prefix . 'origen_fincas';

    // Comprobar si ya existe
    $existe = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE user_id = %d", $current_user->ID ) );

    $data = array(
        'user_id' => $current_user->ID,
        'nombre_finca' => $nombre_finca,
        'ubicacion' => $ubicacion,
        'altura' => $altura,
        'hectareas' => $hectareas,
        'cantidad_plantas' => $cantidad_plantas,
        'variedad_cafe' => $variedad_cafe,
        'edad_cultivo' => $edad_cultivo,
        'densidad_siembra' => $densidad_siembra,
        'tipo_sombra' => $tipo_sombra,
        'sistema_cultivo' => $sistema_cultivo
    );

    $format = array( '%d', '%s', '%s', '%d', '%f', '%d', '%s', '%d', '%d', '%s', '%s' );

    if ( $existe ) {
        // Actualizar
        $result = $wpdb->update( $table_name, $data, array( 'user_id' => $current_user->ID ), $format, array( '%d' ) );
        if ( $result === false ) {
            wp_send_json_error( 'Error al actualizar la finca.' );
        }
        wp_send_json_success( 'Finca actualizada correctamente.' );
    } else {
        // Insertar
        $result = $wpdb->insert( $table_name, $data, $format );
        if ( $result === false ) {
            wp_send_json_error( 'Error al registrar la finca.' );
        }
        wp_send_json_success( 'Finca registrada correctamente.' );
    }
}

// 8. LÓGICA AJAX: REGISTRO
add_action( 'wp_ajax_nopriv_origen_register_action', 'origen_ajax_register' );
function origen_ajax_register() {
    check_ajax_referer( 'origen_auth_nonce', 'nonce' );

    $name  = sanitize_text_field( $_POST['name'] );
    $email = sanitize_email( $_POST['email'] );
    $pass  = $_POST['pass'];

    if ( email_exists( $email ) ) {
        wp_send_json_error( 'El correo ya está registrado.' );
    }

    $user_id = wp_insert_user( array(
        'user_login' => $email,
        'user_pass'  => $pass,
        'user_email' => $email,
        'first_name' => $name,
        'display_name' => $name,
        'role'       => 'caficultor'
    ) );

    if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( $user_id->get_error_message() );
    }

    // Auto-login
    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id );

    wp_send_json_success( 'Cuenta creada. Entrando...' );
}

// 9. LÓGICA AJAX: LOGIN
add_action( 'wp_ajax_nopriv_origen_login_action', 'origen_ajax_login' );
function origen_ajax_login() {
    check_ajax_referer( 'origen_auth_nonce', 'nonce' );

    $user = sanitize_text_field( $_POST['user'] );
    $pass = $_POST['pass'];

    $creds = array(
        'user_login'    => $user,
        'user_password' => $pass,
        'remember'      => true
    );

    $user_signon = wp_signon( $creds, false );

    if ( is_wp_error( $user_signon ) ) {
        wp_send_json_error( 'Usuario o contraseña incorrectos.' );
    }

    wp_send_json_success( 'Acceso correcto. Cargando dashboard...' );
}