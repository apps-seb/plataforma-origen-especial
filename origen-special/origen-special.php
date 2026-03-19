<?php
/**
 * Plugin Name: Origen SPECIAL Agro Platform
 * Description: Plataforma tipo app premium para la cadena de valor del café. Tienda Inteligente.
 * Version: 4.2.0 (Diseño Tienda y Scroll)
 * Author: Tu Nombre
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

// ========================================================================
// 1. ACTIVACIÓN DEL PLUGIN Y REGISTRO DE OPCIONES
// ========================================================================
register_activation_hook( __FILE__, 'origen_special_activate' );
function origen_special_activate() {
    $roles = array(
        'caficultor' => 'Caficultor',
        'asociacion_cafe' => 'Asociación de Caficultores',
        'comprador_cafe' => 'Comprador de Café'
    );

    foreach ($roles as $slug => $name) {
        if ( ! get_role( $slug ) ) {
            add_role( $slug, $name, array( 'read' => true ) );
        }
    }

    if ( ! get_page_by_path( 'caficultores' ) ) {
        wp_insert_post( array(
            'post_title'  => 'Caficultores',
            'post_name'   => 'caficultores',
            'post_status' => 'publish',
            'post_type'   => 'page'
        ) );
    }

    global $wpdb;
    $table_name = $wpdb->prefix . 'origen_fincas';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        nombre_finca varchar(255) NOT NULL,
        ubicacion varchar(255) NOT NULL,
        altura int(11) DEFAULT 0,
        hectareas float DEFAULT 0,
        cantidad_plantas int(11) DEFAULT 0,
        variedad_cafe varchar(100) DEFAULT '',
        edad_cultivo int(11) DEFAULT 0,
        densidad_siembra int(11) DEFAULT 0,
        tipo_sombra varchar(50) DEFAULT '',
        sistema_cultivo varchar(50) DEFAULT '',
        fecha_registro datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        UNIQUE KEY user_id (user_id)
    ) $charset_collate;";

    $table_propuestas = $wpdb->prefix . 'origen_propuestas';
    $sql_propuestas = "CREATE TABLE $table_propuestas (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        valor_produccion decimal(15,2) NOT NULL DEFAULT 0.00,
        valor_solicitado decimal(15,2) NOT NULL DEFAULT 0.00,
        porcentaje_canje decimal(5,2) NOT NULL DEFAULT 0.00,
        estado varchar(50) NOT NULL DEFAULT 'pendiente',
        observaciones text,
        fecha datetime DEFAULT CURRENT_TIMESTAMP,
        productos longtext,
        asesor_id bigint(20) DEFAULT NULL,
        PRIMARY KEY  (id),
        KEY user_id (user_id),
        KEY estado (estado)
    ) $charset_collate;";

    require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
    dbDelta( $sql );
    dbDelta( $sql_propuestas );
}

add_action( 'admin_init', 'origen_special_register_settings' );
function origen_special_register_settings() {
    register_setting( 'origen_special_settings_group', 'origen_precio_manual' );
    register_setting( 'origen_special_settings_group', 'origen_owm_api_key' );
    register_setting( 'origen_special_settings_group', 'origen_descuento_caficultor' );
}

// ========================================================================
// 2. FUNCIONES NÚCLEO: APIs EN TIEMPO REAL (CON CACHÉ)
// ========================================================================
function get_precio_cafe_actual() {
    $precio_cache = get_transient( 'origen_precio_cafe' );
    if ( false !== $precio_cache ) {
        return $precio_cache;
    }

    $precio_fallback = get_option( 'origen_precio_manual', 12000 );
    $precio_final = $precio_fallback;

    $api_url = 'https://api.origen-special.com/v1/precio-cafe';
    $response = wp_remote_get( $api_url, array('timeout' => 3) );

    if ( ! is_wp_error( $response ) && wp_remote_retrieve_response_code( $response ) === 200 ) {
        $body = wp_remote_retrieve_body( $response );
        $data = json_decode( $body, true );
        if ( isset($data['precio_kg']) && is_numeric($data['precio_kg']) ) {
            $precio_final = floatval($data['precio_kg']);
        }
    }

    // Reducir caché del mercado a 5 minutos
    set_transient( 'origen_precio_cafe', $precio_final, 5 * MINUTE_IN_SECONDS );
    return $precio_final;
}

function get_clima_finca( $ubicacion ) {
    if ( empty($ubicacion) ) {
        return false;
    }

    $transient_key = 'origen_clima_' . md5($ubicacion);
    $clima_cache = get_transient( $transient_key );
    if ( false !== $clima_cache ) {
        return $clima_cache;
    }

    $api_key = get_option( 'origen_owm_api_key', '' );
    if ( empty($api_key) ) {
        return false;
    }

    $ciudad = explode(',', $ubicacion)[0];
    $ciudad_url = urlencode( trim($ciudad) . ',CO' );

    $url = "https://api.openweathermap.org/data/2.5/weather?q={$ciudad_url}&units=metric&appid={$api_key}&lang=es";
    $response = wp_remote_get( $url, array('timeout' => 5) );

    if ( is_wp_error( $response ) || wp_remote_retrieve_response_code( $response ) !== 200 ) {
        return false;
    }

    $body = json_decode( wp_remote_retrieve_body( $response ), true );
    if ( !isset($body['main']['temp']) ) {
        return false;
    }

    $datos_clima = array(
        'temp'        => round($body['main']['temp']),
        'humedad'     => $body['main']['humidity'],
        'descripcion' => ucfirst($body['weather'][0]['description']),
        'icono'       => $body['weather'][0]['icon']
    );

    // Reducir caché de clima a 15 minutos
    set_transient( $transient_key, $datos_clima, 15 * MINUTE_IN_SECONDS );
    return $datos_clima;
}

// ========================================================================
// 3. WOOCOMMERCE: DESCUENTOS POR ROL
// ========================================================================
add_filter( 'woocommerce_product_get_price', 'origen_descuento_rol_caficultor', 99, 2 );
add_filter( 'woocommerce_product_variation_get_price', 'origen_descuento_rol_caficultor', 99, 2 );
function origen_descuento_rol_caficultor( $price, $product ) {
    if ( is_user_logged_in() ) {
        $user = wp_get_current_user();
        if ( in_array( 'caficultor', (array) $user->roles ) ) {
            $descuento = floatval( get_option('origen_descuento_caficultor', 10) );
            if ( $descuento > 0 && $descuento <= 100 ) {
                return $price - ( $price * ( $descuento / 100 ) );
            }
        }
    }
    return $price;
}

// ========================================================================
// 4. ENQUEUE SCRIPTS & INTERCEPTAR PLANTILLA
// ========================================================================
add_action( 'wp_enqueue_scripts', 'origen_special_assets' );
function origen_special_assets() {
    if ( is_page( 'caficultores' ) ) {
        $css_ver = file_exists(plugin_dir_path(__FILE__) . 'assets/css/style.css') ? filemtime(plugin_dir_path(__FILE__) . 'assets/css/style.css') : '4.2.0';
        $js_ver = file_exists(plugin_dir_path(__FILE__) . 'assets/js/app.js') ? filemtime(plugin_dir_path(__FILE__) . 'assets/js/app.js') : '4.2.0';

        wp_enqueue_style( 'origen-style', plugin_dir_url( __FILE__ ) . 'assets/css/style.css', array(), $css_ver );
        wp_enqueue_script( 'origen-js', plugin_dir_url( __FILE__ ) . 'assets/js/app.js', array('jquery'), $js_ver, true );

        wp_localize_script( 'origen-js', 'origenApp', array(
            'ajax_url'      => admin_url( 'admin-ajax.php' ),
            'nonce'         => wp_create_nonce( 'origen_auth_nonce' ),
            'dashboard_url' => site_url( '/caficultores/' ),
            'precio_actual' => get_precio_cafe_actual()
        ) );
    }
}

add_filter( 'template_include', 'origen_special_template' );
function origen_special_template( $template ) {
    if ( is_page( 'caficultores' ) ) {
        nocache_headers();
        $plugin_template = plugin_dir_path( __FILE__ ) . 'templates/app-layout.php';
        if ( file_exists( $plugin_template ) ) {
            return $plugin_template;
        }
    }
    return $template;
}

// ========================================================================
// 5. SHORTCODE: REGISTRO / LOGIN
// ========================================================================
add_shortcode( 'origen_special_register', 'origen_special_register_html' );
function origen_special_register_html() {
    ob_start();
    $is_logged_in = is_user_logged_in();

    if ( ! $is_logged_in ) :
    ?>
    <div class="origen-landing-hero">
        <h2 class="hero-title"><i class="ph-fill ph-plant"></i> Portal Exclusivo para Caficultores</h2>
        <p class="hero-desc">Un espacio profesional y robusto diseñado para potenciar tu labor cafetera. Toma el control de tu producción y maximiza tu rentabilidad.</p>

        <ul class="hero-features">
            <li><i class="ph ph-trend-up"></i> <span>Vende tu café al mejor precio del mercado.</span></li>
            <li><i class="ph ph-sliders"></i> <span>Simula los precios dinámicos y analiza el mercado.</span></li>
            <li><i class="ph ph-calculator"></i> <span>Calcula proyecciones de cosecha con alta precisión.</span></li>
            <li><i class="ph ph-arrows-left-right"></i> <span>Canjea tu café por insumos de la tienda Mercacol.</span></li>
            <li><i class="ph ph-star"></i> <span>Accede a beneficios y precios especiales exclusivos.</span></li>
        </ul>

        <button id="btn-show-auth" class="origen-btn hero-btn">
            <i class="ph ph-user-plus"></i> Regístrate Ahora
        </button>
    </div>
    <?php endif; ?>

    <div id="origen-auth-box" class="origen-auth-box" style="<?php echo $is_logged_in ? '' : 'display: none;'; ?>">
        <div class="origen-tabs">
            <button class="origen-tab-btn active" data-target="login">
                <i class="ph ph-sign-in"></i> Ingresar
            </button>
            <button class="origen-tab-btn" data-target="register">
                <i class="ph ph-user-plus"></i> Crear Cuenta
            </button>
        </div>

        <form id="origen-login-form" class="origen-form active">
            <div class="origen-input-group">
                <label>Email o Usuario</label>
                <div class="input-with-icon">
                    <i class="ph ph-envelope-simple"></i>
                    <input type="text" id="log_user" required>
                </div>
            </div>
            <div class="origen-input-group">
                <label>Contraseña</label>
                <div class="input-with-icon">
                    <i class="ph ph-lock-key"></i>
                    <input type="password" id="log_pass" required>
                    <button type="button" class="toggle-pass" tabindex="-1"><i class="ph ph-eye"></i></button>
                </div>
            </div>
            <button type="submit" class="origen-btn"><i class="ph ph-arrow-right"></i> Acceder</button>
        </form>

        <form id="origen-register-form" class="origen-form" style="display: none;">
            <div class="origen-grid-2">
                <div class="origen-input-group">
                    <label>Nombre(s)</label>
                    <input type="text" id="reg_name" required>
                </div>
                <div class="origen-input-group">
                    <label>Apellido(s)</label>
                    <input type="text" id="reg_lastname" required>
                </div>
            </div>

            <div class="origen-grid-2">
                <div class="origen-input-group">
                    <label>Cédula o C. Cafetera</label>
                    <input type="number" id="reg_id_number" required>
                </div>
                <div class="origen-input-group">
                    <label>Perfil</label>
                    <select id="reg_role" required>
                        <option value="">Selecciona...</option>
                        <option value="caficultor">Caficultor / Productor</option>
                        <option value="asociacion_cafe">Asociación</option>
                        <option value="comprador_cafe">Comprador</option>
                    </select>
                </div>
            </div>

            <div id="cond_caficultor" class="origen-conditional-fields" style="display:none;">
                <div class="origen-grid-2">
                    <div class="origen-input-group">
                        <label>Nombre de la Finca</label>
                        <input type="text" id="reg_finca">
                    </div>
                    <div class="origen-input-group">
                        <label>Tamaño</label>
                        <select id="reg_tamano_prod">
                            <option value="pequeno">Pequeño Productor</option>
                            <option value="mediano">Mediano Productor</option>
                            <option value="grande">Grande Productor</option>
                        </select>
                    </div>
                </div>
            </div>

            <div id="cond_asociacion" class="origen-conditional-fields" style="display:none;">
                <div class="origen-input-group">
                    <label>Asociación</label>
                    <select id="reg_asoc_lista">
                        <option value="">Seleccionar...</option>
                        <option value="Caficauca">Caficauca</option>
                        <option value="Cosurca">Cosurca</option>
                        <option value="Fondo Paez">Fondo Paez</option>
                        <option value="Nuevo Futuro">Nuevo Futuro</option>
                        <option value="Cencoic">Cencoic</option>
                        <option value="otra">Otra (Escribir)</option>
                    </select>
                </div>
                <div class="origen-input-group" id="wrap_otra_asoc" style="display:none;">
                    <label>Nombre</label>
                    <input type="text" id="reg_asoc_otra">
                </div>
            </div>

            <div id="cond_comprador" class="origen-conditional-fields" style="display:none;">
                <div class="origen-input-group">
                    <label>Tipo de Comprador</label>
                    <select id="reg_tipo_comprador">
                        <option value="comprador_local">Comprador Local</option>
                        <option value="comercializador">Comercializador</option>
                        <option value="tostador">Tostador</option>
                        <option value="exportador">Exportador</option>
                    </select>
                </div>
            </div>

            <div class="origen-grid-2">
                <div class="origen-input-group">
                    <label>Departamento</label>
                    <select id="reg_depto" required>
                        <option value="">Seleccione...</option>
                        <option value="Cauca">Cauca</option>
                        <option value="Huila">Huila</option>
                        <option value="Nariño">Nariño</option>
                        <option value="Antioquia">Antioquia</option>
                    </select>
                </div>
                <div class="origen-input-group">
                    <label>Municipio</label>
                    <select id="reg_muni" required>
                        <option value="">Depto primero</option>
                    </select>
                </div>
            </div>

            <div class="origen-input-group">
                <label>Correo Electrónico</label>
                <div class="input-with-icon">
                    <i class="ph ph-envelope-simple"></i>
                    <input type="email" id="reg_email" required>
                </div>
            </div>

            <div class="origen-input-group">
                <label>Contraseña <span class="hint">(Mín. 8 caracteres)</span></label>
                <div class="input-with-icon">
                    <i class="ph ph-lock-key"></i>
                    <input type="password" id="reg_pass" required minlength="8">
                    <button type="button" class="toggle-pass" tabindex="-1"><i class="ph ph-eye"></i></button>
                </div>
            </div>

            <button type="submit" class="origen-btn"><i class="ph ph-check-circle"></i> Registrarse</button>
        </form>
        <div id="origen-msg" class="origen-msg"></div>
    </div>
    <?php return ob_get_clean();
}

// ========================================================================
// 6. SHORTCODE: FINCA (CRUD)
// ========================================================================
add_shortcode( 'origen_special_finca', 'origen_special_finca_html' );
function origen_special_finca_html() {
    if ( ! is_user_logged_in() ) return '';

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'origen_fincas';
    $finca = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE user_id = %d", $user_id ) );

    $val_nombre = $finca ? $finca->nombre_finca : get_user_meta($user_id, 'nombre_finca', true);
    $val_ubic = $finca ? $finca->ubicacion : (get_user_meta($user_id, 'municipio', true) . ', ' . get_user_meta($user_id, 'departamento', true));

    ob_start(); ?>
    <form id="origen-finca-form">
        <div class="origen-input-group">
            <label>Nombre de la Finca</label>
            <input type="text" id="finca_nombre" value="<?php echo esc_attr($val_nombre); ?>" required>
        </div>
        <div class="origen-input-group">
            <label>Ubicación (Municipio, Departamento)</label>
            <input type="text" id="finca_ubicacion" value="<?php echo esc_attr($val_ubic); ?>" required>
        </div>

        <div class="origen-grid-2">
            <div class="origen-input-group">
                <label>Altura (m.s.n.m)</label>
                <input type="number" id="finca_altura" value="<?php echo $finca ? esc_attr($finca->altura) : ''; ?>" required>
            </div>
            <div class="origen-input-group">
                <label>Hectáreas Totales</label>
                <input type="number" step="0.01" id="finca_hectareas" value="<?php echo $finca ? esc_attr($finca->hectareas) : ''; ?>" required>
            </div>
        </div>

        <div class="origen-grid-2">
            <div class="origen-input-group">
                <label>Cantidad de Plantas</label>
                <input type="number" id="finca_plantas" value="<?php echo $finca ? esc_attr($finca->cantidad_plantas) : ''; ?>" required>
            </div>
            <div class="origen-input-group">
                <label>Densidad (Plantas/ha)</label>
                <input type="number" id="finca_densidad" value="<?php echo $finca ? esc_attr($finca->densidad_siembra) : ''; ?>">
            </div>
        </div>

        <div class="origen-grid-2">
            <div class="origen-input-group">
                <label>Variedad Principal</label>
                <select id="finca_variedad" required>
                    <?php
                    $variedades = ['Castillo', 'Caturra', 'Colombia', 'Typica', 'Bourbon', 'Geisha', 'Tabi', 'Otra'];
                    foreach($variedades as $var) {
                        $sel = ($finca && $finca->variedad_cafe === $var) ? 'selected' : '';
                        echo "<option value='$var' $sel>$var</option>";
                    }
                    ?>
                </select>
            </div>
            <div class="origen-input-group">
                <label>Edad del Cultivo (Años)</label>
                <input type="number" id="finca_edad" value="<?php echo $finca ? esc_attr($finca->edad_cultivo) : ''; ?>" required>
            </div>
        </div>

        <div class="origen-grid-2">
            <div class="origen-input-group">
                <label>Tipo de Sombra</label>
                <select id="finca_sombra" required>
                    <option value="Libre exposición" <?php if($finca && $finca->tipo_sombra == 'Libre exposición') echo 'selected'; ?>>Libre exposición</option>
                    <option value="Sombra parcial" <?php if($finca && $finca->tipo_sombra == 'Sombra parcial') echo 'selected'; ?>>Sombra parcial</option>
                    <option value="Sombra densa" <?php if($finca && $finca->tipo_sombra == 'Sombra densa') echo 'selected'; ?>>Sombra densa</option>
                </select>
            </div>
            <div class="origen-input-group">
                <label>Sistema de Cultivo</label>
                <select id="finca_sistema" required>
                    <option value="Tradicional" <?php if($finca && $finca->sistema_cultivo == 'Tradicional') echo 'selected'; ?>>Tradicional</option>
                    <option value="Tecnificado" <?php if($finca && $finca->sistema_cultivo == 'Tecnificado') echo 'selected'; ?>>Tecnificado</option>
                    <option value="Orgánico" <?php if($finca && $finca->sistema_cultivo == 'Orgánico') echo 'selected'; ?>>Orgánico Certificado</option>
                </select>
            </div>
        </div>

        <button type="submit" class="origen-btn"><i class="ph ph-floppy-disk"></i> Guardar Perfil</button>
        <div id="origen-finca-msg" class="origen-msg"></div>
    </form>
    <?php return ob_get_clean();
}

// ========================================================================
// 7. SHORTCODE: MOTOR DE PRODUCCIÓN
// ========================================================================
add_shortcode( 'origen_special_produccion', 'origen_special_produccion_html' );
function origen_special_produccion_html() {
    if ( ! is_user_logged_in() ) return '';
    $precio_mercado = get_precio_cafe_actual();
    ob_start(); ?>
    <div class="origen-calc-wrapper">
        <div class="origen-tabs sub-tabs">
            <button class="origen-tab-btn active" data-target="calc-auto">
                <i class="ph ph-database"></i> Mi Cosecha Base
            </button>
            <button class="origen-tab-btn" data-target="calc-sim">
                <i class="ph ph-sliders"></i> Simulador Dinámico
            </button>
        </div>

        <div id="origen-calc-auto-form" class="origen-form active">
            <div class="realtime-data-panel">
                <div class="rt-card market-rt">
                    <i class="ph ph-trend-up"></i>
                    <div>
                        <span>Precio Referencia</span>
                        <strong>$<?php echo number_format($precio_mercado, 0, ',', '.'); ?> <small>COP/kg</small></strong>
                    </div>
                </div>
                <div class="rt-card weather-rt" id="weather-widget">
                    <i class="ph ph-cloud-sun"></i>
                    <div>
                        <span>Clima en Finca</span>
                        <strong id="weather-text">Consultando...</strong>
                    </div>
                </div>
            </div>

            <p style="color:var(--text-muted); font-size:14px; margin-bottom:20px;">Estima tu producción anual con los datos de tu finca y el precio actual.</p>
            <button id="btn-calcular-cosecha" class="origen-btn"><i class="ph ph-math-operations"></i> Calcular Producción Base</button>
            <div id="origen-calc-msg" class="origen-msg"></div>

            <div id="origen-calc-results" style="display:none; margin-top:30px;">
                <div class="calc-indicator-box">
                    <div class="calc-header">
                        <h4>Rendimiento Actual:</h4>
                        <span id="res-indicador" class="badge-rendimiento">...</span>
                    </div>
                    <div class="progress-bar-bg"><div id="res-bar" class="progress-bar-fill"></div></div>
                </div>
                <div class="origen-grid-3">
                    <div class="calc-card">
                        <i class="ph ph-scales"></i>
                        <h5>Producción Anual</h5>
                        <div><strong id="res-kg">0</strong> <small>kg</small></div>
                    </div>
                    <div class="calc-card">
                        <i class="ph ph-package"></i>
                        <h5>Sacos (60kg)</h5>
                        <div><strong id="res-sacos">0</strong> <small>und</small></div>
                    </div>
                    <div class="calc-card highlight">
                        <i class="ph ph-currency-circle-dollar"></i>
                        <h5>Valor Estimado</h5>
                        <div><strong id="res-valor">0</strong> <small>COP</small></div>
                    </div>
                </div>
            </div>
        </div>

        <div id="origen-calc-sim-form" class="origen-form" style="display: none;">
            <div class="simulador-controles">
                <div class="origen-input-group">
                    <label>Hectáreas (ha): <span id="val-sim-ha" class="sim-val">1</span></label>
                    <input type="range" id="sim_ha" min="0.5" max="50" step="0.5" value="1">
                </div>
                <div class="origen-input-group">
                    <label>Densidad (Plantas/ha): <span id="val-sim-densidad" class="sim-val">5000</span></label>
                    <input type="range" id="sim_densidad" min="2000" max="10000" step="100" value="5000">
                </div>

                <div class="origen-grid-2">
                    <div class="origen-input-group">
                        <label>Variedad</label>
                        <select id="sim_variedad">
                            <option value="1.0">Castillo / Colombia</option>
                            <option value="0.9">Caturra / Tabi</option>
                            <option value="0.85">Bourbon / Typica</option>
                            <option value="0.75">Geisha</option>
                        </select>
                    </div>
                    <div class="origen-input-group">
                        <label>Edad Cultivo</label>
                        <select id="sim_edad">
                            <option value="0">0 - 2 años (Crecimiento)</option>
                            <option value="0.8">2 - 3 años (Inicio)</option>
                            <option value="1.0" selected>3 - 7 años (Plena Prod.)</option>
                            <option value="0.7">Más de 7 años (Zoca)</option>
                        </select>
                    </div>
                </div>

                <div class="origen-grid-2">
                    <div class="origen-input-group">
                        <label>Nivel de Fertilización</label>
                        <select id="sim_fertilizacion">
                            <option value="1.1">Óptima (Plan Nutricional)</option>
                            <option value="0.9" selected>Media (Tradicional)</option>
                            <option value="0.6">Baja / Deficiente</option>
                        </select>
                    </div>
                    <div class="origen-input-group">
                        <label>Merma por Humedad: <span id="val-sim-humedad" class="sim-val">12%</span></label>
                        <input type="range" id="sim_humedad" min="10" max="25" step="1" value="12">
                        <span class="hint" id="hint-humedad">Humedad óptima CPS (10%-12%)</span>
                    </div>
                </div>

                <div class="origen-grid-2">
                    <div class="origen-input-group">
                        <label>Pérdidas (Plagas/Enf.): <span id="val-sim-perdidas" class="sim-val">5%</span></label>
                        <input type="range" id="sim_perdidas" min="0" max="40" step="1" value="5">
                    </div>
                    <div class="origen-input-group">
                        <label>Precio Venta (COP/Kg)</label>
                        <input type="number" id="sim_precio" value="<?php echo esc_attr($precio_mercado); ?>" step="100">
                    </div>
                </div>

                <div class="origen-input-group">
                    <label>Costo de Producción por Kg (COP)</label>
                    <input type="number" id="sim_costo" value="9500" step="100">
                    <span class="hint">Incluye recolección, insumos y proceso.</span>
                </div>
            </div>

            <div class="origen-grid-2" style="margin-top: 25px;">
                <div class="calc-card">
                    <i class="ph ph-scales"></i>
                    <h5>Producción Neta</h5>
                    <div><strong id="sim-res-kg">0</strong> <small>kg</small></div>
                </div>
                <div class="calc-card">
                    <i class="ph ph-package"></i>
                    <h5>Sacos (60kg)</h5>
                    <div><strong id="sim-res-sacos">0</strong> <small>und</small></div>
                </div>
            </div>
            <div class="origen-grid-2" style="margin-top: 15px;">
                <div class="calc-card">
                    <i class="ph ph-money"></i>
                    <h5>Ingresos Brutos</h5>
                    <div><strong id="sim-res-ingreso">0</strong> <small>COP</small></div>
                </div>
                <div class="calc-card">
                    <i class="ph ph-calculator"></i>
                    <h5>Costos Totales</h5>
                    <div><strong id="sim-res-costo">0</strong> <small>COP</small></div>
                </div>
            </div>
            <div class="calc-indicator-box" style="margin-top: 15px;">
                <div class="calc-header">
                    <h4>Utilidad Neta Esperada</h4>
                    <span id="sim-res-margen-badge" class="badge-rendimiento" style="background:#10b981;">0% Margen</span>
                </div>
                <div style="text-align:center; font-size:32px; font-weight:800; color:var(--text-main);" id="sim-res-utilidad">$0 <small style="font-size:14px; color:var(--text-muted); font-weight:400;">COP</small></div>
            </div>
        </div>
    </div>
    <?php return ob_get_clean();
}

// ========================================================================
// 8. SHORTCODE: TIENDA WOOCOMMERCE INTELIGENTE Y RECOMENDACIONES PRECISAS
// ========================================================================
add_shortcode( 'origen_special_tienda', 'origen_special_tienda_html' );
function origen_special_tienda_html() {
    if ( ! is_user_logged_in() ) {
        return '<div class="origen-msg error" style="display:block;">Debes iniciar sesión para ver la tienda.</div>';
    }
    if ( ! class_exists( 'WooCommerce' ) ) {
        return '<div class="origen-msg error" style="display:block;">La tienda está en mantenimiento (WooCommerce inactivo).</div>';
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'origen_fincas';
    $finca = $wpdb->get_row( $wpdb->prepare( "SELECT hectareas, cantidad_plantas FROM $table_name WHERE user_id = %d", $user_id ) );

    $hectareas = $finca ? floatval($finca->hectareas) : 0;
    $plantas = $finca ? intval($finca->cantidad_plantas) : 0;

    // Categorías en WooCommerce
    $categorias = array('fertilizantes', 'herramientas', 'insumos-agricolas', 'abonos');

    // LÓGICA DE DIAGNÓSTICO: Nombres específicos y amigables.
    if ( $hectareas > 0 ) {
        $bultos_fert = ceil($hectareas * 12);
        $kg_abono = ceil($plantas * 0.5);

        $sugerencia_msg = "
        <div class='recomendacion-box'>
            <div class='recom-title'><i class='ph ph-sparkle'></i> Recomendación Técnica de Cultivo</div>
            <p>Para tu finca de <strong>{$hectareas} hectáreas</strong> ({$plantas} plantas), te sugerimos aplicar por ciclo:</p>
            <ul>
                <li>🌱 <strong>{$bultos_fert} bultos (50kg)</strong> de Fertilizante (Ej: Triple 15, Producción o Agrimins).</li>
                <li>🍂 <strong>{$kg_abono} kg</strong> de Abono Orgánico (Ej: Compost, Humus o Gallinaza).</li>
                <li>🛡️ <strong>Preventivos:</strong> Fungicidas (Ej: Mancozeb, Nativo) para control de roya.</li>
            </ul>
            <p><small>Busca estos productos o equivalentes en la tienda con tu descuento aplicado.</small></p>
        </div>";
    } else {
        $sugerencia_msg = "<div class='recomendacion-box'><div class='recom-title'><i class='ph ph-info'></i> Información</div><p>💡 Completa los datos de 'Mi Finca' para recibir recomendaciones exactas de productos y cantidades basadas en tu hectariaje.</p></div>";
    }

    $args = array(
        'post_type'      => 'product',
        'posts_per_page' => 10,
        'post_status'    => 'publish',
        'tax_query'      => array(
            array(
                'taxonomy' => 'product_cat',
                'field'    => 'slug',
                'terms'    => $categorias,
                'operator' => 'IN'
            )
        )
    );

    $loop = new WP_Query( $args );

    ob_start(); ?>
    <div class="origen-tienda-wrapper">

        <?php echo $sugerencia_msg; ?>

        <?php if ( $loop->have_posts() ) : ?>
            <div class="origen-store-grid">
                <?php while ( $loop->have_posts() ) : $loop->the_post();
                    global $product;
                    $regular_price = $product->get_regular_price();
                    $sale_price = $product->get_price();
                ?>
                    <div class="origen-product-card">
                        <div class="product-img-box">
                            <?php echo $product->get_image('woocommerce_thumbnail'); ?>
                            <?php if($regular_price > $sale_price): ?>
                                <span class="discount-badge">-10% Asociado</span>
                            <?php endif; ?>
                        </div>
                        <div class="product-info">
                            <h4 class="product-title"><?php echo get_the_title(); ?></h4>
                            <div class="price-box">
                                <?php if($regular_price > $sale_price): ?>
                                    <del><?php echo wc_price($regular_price); ?></del>
                                <?php endif; ?>
                                <strong><?php echo wc_price($sale_price); ?></strong>
                            </div>

                            <div class="origen-qty-cart">
                                <input type="number" class="origen-qty-input" value="1" min="1" step="1" data-target-btn="<?php echo esc_attr($product->get_id()); ?>">
                                <a href="?add-to-cart=<?php echo esc_attr($product->get_id()); ?>" data-quantity="1" class="origen-btn-buy button add_to_cart_button ajax_add_to_cart" id="btn-add-<?php echo esc_attr($product->get_id()); ?>" data-product_id="<?php echo esc_attr($product->get_id()); ?>">
                                    <i class="ph ph-shopping-cart"></i> Añadir
                                </a>
                            </div>
                        </div>
                    </div>
                <?php endwhile; wp_reset_postdata(); ?>
            </div>
        <?php else : ?>
            <div class="origen-msg error" style="display:block; text-align:left;">
                <i class="ph ph-warning-circle" style="font-size:24px; margin-bottom:10px; display:block;"></i>
                <strong>La tienda aún no tiene productos vinculados.</strong><br><br>
                Para ver productos aquí, ve a <b>WordPress > Productos</b> y asígnales alguna de estas categorías: <b>fertilizantes</b>, <b>herramientas</b> o <b>abonos</b>.
            </div>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

// ========================================================================
// 9. SHORTCODE: DASHBOARD (NAVEGACIÓN SPA)
// ========================================================================
add_shortcode( 'origen_special_dashboard', 'origen_special_dashboard_html' );
function origen_special_dashboard_html() {
    $current_user = wp_get_current_user();
    $role = empty($current_user->roles) ? '' : $current_user->roles[0];

    $cedula = get_user_meta( $current_user->ID, 'cedula_cafetera', true );
    $num_tarjeta = $cedula ? str_pad($cedula, 12, "0", STR_PAD_LEFT) : str_pad($current_user->ID, 12, "0", STR_PAD_LEFT);
    $num_formateado = chunk_split($num_tarjeta, 4, ' ');
    $vencimiento = date( 'm/y', strtotime( '+3 years', strtotime( $current_user->user_registered ) ) );

    ob_start(); ?>
    <div class="origen-dashboard">

        <div id="origen-view-home" class="origen-main-view">
            <div class="dash-header-user">
                <div class="avatar"><i class="ph ph-user"></i></div>
                <div>
                    <h2>Hola, <?php echo esc_html( $current_user->first_name ); ?></h2>
                    <span class="badge-role"><?php echo esc_html( translate_user_role( wp_roles()->roles[ $role ]['name'] ) ); ?></span>
                </div>
            </div>

            <div class="origen-grid">
                <?php if($role === 'caficultor'): ?>
                    <div class="puntos-disponibles-card" style="background: linear-gradient(135deg, #10b981, #059669); color: white; padding: 20px; border-radius: 12px; margin-bottom: 20px; text-align: center;">
                        <i class="ph ph-coins" style="font-size: 32px; margin-bottom: 10px;"></i>
                        <h4 style="margin: 0; font-size: 14px; font-weight: 500; opacity: 0.9;">Puntos Disponibles</h4>
                        <?php
                        $puntos = get_user_meta( $current_user->ID, 'origen_puntos', true );
                        $puntos = $puntos ? floatval($puntos) : 0;
                        ?>
                        <div style="font-size: 28px; font-weight: 700; margin-top: 5px;"><?php echo number_format($puntos, 0, ',', '.'); ?> <small style="font-size: 14px; font-weight: 400; opacity: 0.8;">PTS</small></div>
                    </div>

                    <button class="origen-card-btn nav-trigger" data-target="origen-view-finca">
                        <i class="ph ph-plant"></i> Mi Finca / Cultivo
                    </button>
                    <button class="origen-card-btn nav-trigger" data-target="origen-view-produccion">
                        <i class="ph ph-calculator"></i> Proyección Cosecha
                    </button>
                    <button class="origen-card-btn nav-trigger" data-target="origen-view-tienda">
                        <i class="ph ph-storefront"></i> Tienda Agro
                    </button>
                    <button class="origen-card-btn nav-trigger" data-target="origen-view-canje" style="background-color: var(--primary-color); color: white; border-color: var(--primary-color);">
                        <i class="ph ph-arrows-left-right"></i> Solicitar Canje
                    </button>
                    <button class="origen-card-btn nav-trigger" data-target="origen-view-solicitudes">
                        <i class="ph ph-list-dashes"></i> Mis Solicitudes
                    </button>
                <?php endif; ?>

                <?php if ( class_exists( 'WooCommerce' ) ): ?>
                    <a href="<?php echo esc_url( wc_get_cart_url() ); ?>" class="origen-card-btn">
                        <i class="ph ph-shopping-cart"></i> Ver mi Carrito
                    </a>
                <?php endif; ?>

                <a href="<?php echo esc_url( wp_logout_url( site_url( '/caficultores' ) ) ); ?>" class="origen-btn-outline">
                    <i class="ph ph-sign-out"></i> Cerrar Sesión
                </a>
            </div>
        </div>

        <div id="origen-view-finca" class="origen-main-view" style="display: none;">
            <button class="origen-back-btn nav-trigger" data-target="origen-view-home"><i class="ph ph-arrow-left"></i> Volver al Inicio</button>
            <?php if($role === 'caficultor'): ?>
            <div class="origen-id-card">
                <div class="card-header-id"><span>Origen SPECIAL</span><i class="ph ph-contactless-payment"></i></div>
                <div class="card-chip"><div class="chip-inner"></div></div>
                <div class="card-number"><?php echo esc_html( trim($num_formateado) ); ?></div>
                <div class="card-footer-id">
                    <div class="card-name"><small>CAFICULTOR ASOCIADO</small><div><?php echo esc_html( strtoupper($current_user->display_name) ); ?></div></div>
                    <div class="card-exp"><small>VENCE</small><div><?php echo esc_html( $vencimiento ); ?></div></div>
                </div>
            </div>
            <?php endif; ?>
            <div class="view-header"><h3><i class="ph ph-plant"></i> Perfil de Finca</h3><p>Mantén los datos actualizados.</p></div>
            <?php echo do_shortcode('[origen_special_finca]'); ?>
        </div>

        <div id="origen-view-produccion" class="origen-main-view" style="display: none;">
            <button class="origen-back-btn nav-trigger" data-target="origen-view-home"><i class="ph ph-arrow-left"></i> Volver al Inicio</button>
            <div class="view-header"><h3><i class="ph ph-calculator"></i> Motor de Producción</h3><p>Calcula el potencial de tu cosecha.</p></div>
            <?php echo do_shortcode('[origen_special_produccion]'); ?>
        </div>

        <div id="origen-view-tienda" class="origen-main-view" style="display: none;">
            <button class="origen-back-btn nav-trigger" data-target="origen-view-home"><i class="ph ph-arrow-left"></i> Volver al Inicio</button>
            <div class="view-header"><h3><i class="ph ph-storefront"></i> Tienda Especializada</h3><p>Insumos agrícolas con descuento exclusivo.</p></div>
            <?php echo do_shortcode('[origen_special_tienda]'); ?>
        </div>

        <div id="origen-view-canje" class="origen-main-view" style="display: none;">
            <button class="origen-back-btn nav-trigger" data-target="origen-view-home"><i class="ph ph-arrow-left"></i> Volver al Inicio</button>
            <div class="view-header"><h3><i class="ph ph-arrows-left-right"></i> Solicitar Canje</h3><p>Propón un porcentaje de tu producción para canjear por puntos.</p></div>
            <?php echo do_shortcode('[origen_special_canje_form]'); ?>
        </div>

        <div id="origen-view-solicitudes" class="origen-main-view" style="display: none;">
            <button class="origen-back-btn nav-trigger" data-target="origen-view-home"><i class="ph ph-arrow-left"></i> Volver al Inicio</button>
            <div class="view-header"><h3><i class="ph ph-list-dashes"></i> Mis Solicitudes</h3><p>Historial y estado de tus propuestas de canje.</p></div>
            <?php echo do_shortcode('[origen_special_solicitudes_list]'); ?>
        </div>

    </div>
    <?php return ob_get_clean();
}

// ========================================================================
// SHORTCODE: FORMULARIO DE CANJE
// ========================================================================
add_shortcode( 'origen_special_canje_form', 'origen_special_canje_form_html' );
function origen_special_canje_form_html() {
    if ( ! is_user_logged_in() ) return '';

    ob_start(); ?>
    <div class="origen-canje-wrapper">
        <form id="origen-canje-form" class="origen-form">
            <div class="origen-input-group">
                <label>Porcentaje de Producción a Canjear (%)</label>
                <input type="number" id="canje_porcentaje" min="1" max="100" step="1" value="50" required>
                <span class="hint">Indica qué porcentaje del total de tu cosecha deseas ofrecer para obtener puntos en tienda.</span>
            </div>

            <div class="calc-indicator-box" style="margin-top:20px;">
                <div class="calc-header">
                    <h4>Valor Estimado Solicitado</h4>
                    <button type="button" id="btn-calcular-valor-canje" class="origen-btn-outline" style="padding: 5px 10px; font-size: 12px; height: auto;">Calcular / Actualizar</button>
                </div>
                <div style="text-align:center; font-size:32px; font-weight:800; color:var(--primary-color);" id="canje-res-valor">$0 <small style="font-size:14px; color:var(--text-muted); font-weight:400;">COP (Puntos)</small></div>
            </div>

            <div id="canje-info-detalle" style="display:none; margin-top:15px; background:var(--bg-light); padding:15px; border-radius:8px; font-size:13px; color:var(--text-muted);">
                Tu producción total estimada es de <strong id="canje-prod-valor" style="color:var(--text-main);">$0</strong> COP. Solicitando un <strong id="canje-prod-porcentaje">50%</strong>, el valor de canje propuesto sería de <strong id="canje-solicitado-valor" style="color:var(--text-main);">$0</strong> COP.
            </div>

            <div class="origen-input-group" style="margin-top:20px;">
                <label>Observaciones o Productos de Interés (Opcional)</label>
                <textarea id="canje_observaciones" rows="3" placeholder="Ej: Me interesa canjear por fertilizantes y herramientas..."></textarea>
            </div>

            <input type="hidden" id="canje_valor_produccion" value="0">
            <input type="hidden" id="canje_valor_solicitado" value="0">

            <button type="submit" class="origen-btn" id="btn-submit-canje" disabled><i class="ph ph-paper-plane-right"></i> Enviar Propuesta de Canje</button>
            <div id="origen-canje-msg" class="origen-msg"></div>
        </form>
    </div>
    <?php return ob_get_clean();
}

// ========================================================================
// SHORTCODE: LISTADO DE SOLICITUDES (USUARIO)
// ========================================================================
add_shortcode( 'origen_special_solicitudes_list', 'origen_special_solicitudes_list_html' );
function origen_special_solicitudes_list_html() {
    if ( ! is_user_logged_in() ) return '';

    global $wpdb;
    $user_id = get_current_user_id();
    $table_propuestas = $wpdb->prefix . 'origen_propuestas';

    $propuestas = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM $table_propuestas WHERE user_id = %d ORDER BY fecha DESC", $user_id ) );

    ob_start(); ?>
    <div class="origen-solicitudes-wrapper">
        <?php if ( empty($propuestas) ) : ?>
            <div class="origen-msg" style="display:block;">Aún no has enviado ninguna propuesta de canje.</div>
        <?php else : ?>
            <div class="solicitudes-list">
                <?php foreach ( $propuestas as $p ) :
                    $estado_color = '#eab308'; // pendiente
                    $estado_label = 'Pendiente';

                    if ( $p->estado === 'aprobado' ) {
                        $estado_color = '#10b981';
                        $estado_label = 'Aprobada';
                    } elseif ( $p->estado === 'rechazado' ) {
                        $estado_color = '#ef4444';
                        $estado_label = 'Rechazada';
                    } elseif ( $p->estado === 'visita' ) {
                        $estado_color = '#3b82f6';
                        $estado_label = 'Visita Programada';
                    }
                ?>
                <div class="solicitud-card" style="background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:15px; margin-bottom:15px;">
                    <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:10px;">
                        <span style="font-weight:600; color:var(--text-main);">Propuesta #<?php echo esc_html($p->id); ?></span>
                        <span style="background:<?php echo $estado_color; ?>; color:#fff; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase;"><?php echo $estado_label; ?></span>
                    </div>
                    <div class="origen-grid-2" style="margin-bottom:10px;">
                        <div>
                            <small style="color:var(--text-muted); display:block;">Valor Solicitado</small>
                            <strong style="color:var(--primary-color);">$<?php echo number_format($p->valor_solicitado, 0, ',', '.'); ?> COP</strong>
                        </div>
                        <div>
                            <small style="color:var(--text-muted); display:block;">Porcentaje</small>
                            <strong><?php echo floatval($p->porcentaje_canje); ?>%</strong>
                        </div>
                    </div>
                    <div>
                        <small style="color:var(--text-muted); display:block;">Fecha: <?php echo date('d/m/Y', strtotime($p->fecha)); ?></small>
                    </div>
                    <?php if ( !empty($p->observaciones) && $p->estado !== 'pendiente' ) : ?>
                    <div style="margin-top:10px; background:#f8fafc; padding:10px; border-radius:6px; font-size:13px;">
                        <strong>Respuesta:</strong> <?php echo esc_html($p->observaciones); ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php return ob_get_clean();
}

// ========================================================================
// LÓGICAS AJAX CANJE
// ========================================================================
add_action( 'wp_ajax_origen_submit_canje', 'origen_ajax_submit_canje' );
function origen_ajax_submit_canje() {
    check_ajax_referer( 'origen_auth_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Sesión caducada.' );
    }

    $user_id = get_current_user_id();
    $porcentaje = floatval($_POST['porcentaje']);
    $valor_produccion = floatval($_POST['valor_produccion']);
    $valor_solicitado = floatval($_POST['valor_solicitado']);
    $observaciones = sanitize_textarea_field($_POST['observaciones']);

    if ($valor_solicitado <= 0) {
        wp_send_json_error('El valor solicitado debe ser mayor a 0.');
    }

    global $wpdb;
    $table_propuestas = $wpdb->prefix . 'origen_propuestas';

    $data = array(
        'user_id' => $user_id,
        'valor_produccion' => $valor_produccion,
        'valor_solicitado' => $valor_solicitado,
        'porcentaje_canje' => $porcentaje,
        'estado' => 'pendiente',
        'observaciones' => $observaciones,
        'fecha' => current_time('mysql')
    );

    $inserted = $wpdb->insert( $table_propuestas, $data );

    if ($inserted) {
        wp_send_json_success( '¡Propuesta de canje enviada con éxito! Pronto será revisada.' );
    } else {
        wp_send_json_error( 'Error al enviar la propuesta.' );
    }
}

// ========================================================================
// 10. LÓGICAS AJAX
// ========================================================================
add_action( 'wp_ajax_nopriv_origen_register_action', 'origen_ajax_register' );
add_action( 'wp_ajax_origen_register_action', 'origen_ajax_register' );
function origen_ajax_register() {
    check_ajax_referer( 'origen_auth_nonce', 'nonce' );

    $email = sanitize_email( $_POST['email'] );
    $pass = $_POST['pass'];
    $role = sanitize_text_field($_POST['role']);

    $allowed_roles = array('caficultor', 'asociacion_cafe', 'comprador_cafe');
    if (!in_array($role, $allowed_roles)) {
        wp_send_json_error('Rol no permitido.');
    }

    if ( email_exists( $email ) ) {
        wp_send_json_error( 'El correo ya está registrado.' );
    }

    $user_id = wp_insert_user( array(
        'user_login' => $email,
        'user_pass' => $pass,
        'user_email' => $email,
        'first_name' => sanitize_text_field( $_POST['name'] ),
        'last_name' => sanitize_text_field( $_POST['lastname'] ),
        'display_name' => sanitize_text_field( $_POST['name'] ) . ' ' . sanitize_text_field( $_POST['lastname'] ),
        'role' => $role
    ) );

    if ( is_wp_error( $user_id ) ) {
        wp_send_json_error( $user_id->get_error_message() );
    }

    add_user_meta( $user_id, 'cedula_cafetera', sanitize_text_field( $_POST['id_number'] ) );
    add_user_meta( $user_id, 'departamento', sanitize_text_field( $_POST['depto'] ) );
    add_user_meta( $user_id, 'municipio', sanitize_text_field( $_POST['muni'] ) );

    if($role === 'caficultor') {
        add_user_meta( $user_id, 'nombre_finca', sanitize_text_field( $_POST['finca'] ) );
        add_user_meta( $user_id, 'tamano_productor', sanitize_text_field( $_POST['tamano_prod'] ) );
    } elseif ($role === 'asociacion_cafe') {
        add_user_meta( $user_id, 'nombre_asociacion', sanitize_text_field( $_POST['asoc_lista'] === 'otra' ? $_POST['asoc_otra'] : $_POST['asoc_lista'] ) );
    } elseif ($role === 'comprador_cafe') {
        add_user_meta( $user_id, 'tipo_comprador', sanitize_text_field( $_POST['tipo_comprador'] ) );
    }

    wp_set_current_user( $user_id );
    wp_set_auth_cookie( $user_id, true );
    wp_send_json_success( 'OK' );
}

add_action( 'wp_ajax_nopriv_origen_login_action', 'origen_ajax_login' );
add_action( 'wp_ajax_origen_login_action', 'origen_ajax_login' );
function origen_ajax_login() {
    check_ajax_referer( 'origen_auth_nonce', 'nonce' );
    $creds = array(
        'user_login' => sanitize_text_field( $_POST['user'] ),
        'user_password' => $_POST['pass'],
        'remember' => true
    );

    $user_signon = wp_signon( $creds, false );
    if ( is_wp_error( $user_signon ) ) {
        wp_send_json_error( 'Error de credenciales.' );
    }

    wp_send_json_success( 'OK' );
}

add_action( 'wp_ajax_origen_save_finca_action', 'origen_ajax_save_finca' );
function origen_ajax_save_finca() {
    check_ajax_referer( 'origen_auth_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Sesión caducada.' );
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'origen_fincas';

    $data = array(
        'user_id' => $user_id,
        'nombre_finca' => sanitize_text_field( $_POST['f_nombre'] ),
        'ubicacion' => sanitize_text_field( $_POST['f_ubicacion'] ),
        'altura' => intval( $_POST['f_altura'] ),
        'hectareas' => floatval( $_POST['f_hectareas'] ),
        'cantidad_plantas' => intval( $_POST['f_plantas'] ),
        'variedad_cafe' => sanitize_text_field( $_POST['f_variedad'] ),
        'edad_cultivo' => intval( $_POST['f_edad'] ),
        'densidad_siembra' => intval( $_POST['f_densidad'] ),
        'tipo_sombra' => sanitize_text_field( $_POST['f_sombra'] ),
        'sistema_cultivo' => sanitize_text_field( $_POST['f_sistema'] )
    );

    $exists = $wpdb->get_var( $wpdb->prepare( "SELECT id FROM $table_name WHERE user_id = %d", $user_id ) );

    if ( $exists ) {
        $wpdb->update( $table_name, $data, array( 'id' => $exists ) );
    } else {
        $wpdb->insert( $table_name, $data );
    }

    wp_send_json_success( '¡Datos de finca guardados!' );
}

add_action( 'wp_ajax_origen_calculate_production', 'origen_ajax_calculate_production' );
function origen_ajax_calculate_production() {
    check_ajax_referer( 'origen_auth_nonce', 'nonce' );
    if ( ! is_user_logged_in() ) {
        wp_send_json_error( 'Sesión caducada.' );
    }

    global $wpdb;
    $user_id = get_current_user_id();
    $table_name = $wpdb->prefix . 'origen_fincas';
    $finca = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM $table_name WHERE user_id = %d", $user_id ) );

    if( !$finca || empty($finca->cantidad_plantas) ) {
        wp_send_json_error( 'Aún no has guardado los datos de tu finca.' );
    }

    $precio_actual = get_precio_cafe_actual();
    $clima_actual = get_clima_finca( $finca->ubicacion );

    $plantas = (int) $finca->cantidad_plantas;
    $edad = (int) $finca->edad_cultivo;
    $sistema = $finca->sistema_cultivo;
    $variedad = $finca->variedad_cafe;
    $hectareas = (float) $finca->hectareas;

    $rendimiento_base = 0.8;
    $ajuste_edad = ($edad <= 2) ? 0 : (($edad <= 5) ? 0.7 : 1.0);
    $ajuste_sistema = ($sistema === 'Tecnificado') ? 1.0 : (($sistema === 'Orgánico') ? 0.9 : 0.8);
    $ajuste_variedad = in_array($variedad, ['Castillo', 'Colombia']) ? 1.0 : ($variedad === 'Caturra' ? 0.9 : 0.85);

    $produccion_kg = $plantas * $rendimiento_base * $ajuste_edad * $ajuste_sistema * $ajuste_variedad;
    $sacos = $produccion_kg / 60;
    $valor_total = $produccion_kg * $precio_actual;
    $rendimiento_ha = $hectareas > 0 ? ($produccion_kg / $hectareas) : 0;

    $indicador = 'Bajo'; $color = '#ef4444'; $porcentaje = 25;

    if ($produccion_kg == 0) {
        $indicador = 'Cultivo Joven'; $color = '#94a3b8'; $porcentaje = 5;
    } elseif ($rendimiento_ha >= 2500) {
        $indicador = 'Alto (Óptimo)'; $color = '#10b981'; $porcentaje = 95;
    } elseif ($rendimiento_ha >= 1000) {
        $indicador = 'Medio (Promedio)'; $color = '#eab308'; $porcentaje = 60;
    }

    wp_send_json_success( array(
        'kg'         => number_format($produccion_kg, 1, ',', '.'),
        'sacos'      => number_format($sacos, 1, ',', '.'),
        'valor'      => number_format($valor_total, 0, ',', '.'),
        'indicador'  => $indicador,
        'color'      => $color,
        'porcentaje' => $porcentaje,
        'clima'      => $clima_actual
    ) );
}

// ========================================================================
// PROCESAR PROPUESTAS DE CANJE (ADMIN)
// ========================================================================
add_action( 'admin_post_origen_procesar_propuesta', 'origen_procesar_propuesta_action' );
function origen_procesar_propuesta_action() {
    if ( ! current_user_can( 'manage_options' ) ) {
        wp_die('Acceso denegado.');
    }

    check_admin_referer( 'origen_procesar_propuesta_nonce' );

    global $wpdb;
    $table_propuestas = $wpdb->prefix . 'origen_propuestas';

    $propuesta_id = intval($_POST['propuesta_id']);
    $estado_nuevo = sanitize_text_field($_POST['estado_nuevo']);
    $respuesta_asesor = sanitize_textarea_field($_POST['respuesta_asesor']);
    $asesor_id = get_current_user_id();

    $propuesta = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table_propuestas WHERE id = %d", $propuesta_id) );

    if (!$propuesta) {
        wp_die('Propuesta no encontrada.');
    }

    // Si ya está aprobada o rechazada, no se puede cambiar
    if ($propuesta->estado === 'aprobado' || $propuesta->estado === 'rechazado') {
        wp_die('Esta propuesta ya fue procesada.');
    }

    $data = array(
        'estado' => $estado_nuevo,
        'observaciones' => $respuesta_asesor,
        'asesor_id' => $asesor_id
    );

    $wpdb->update( $table_propuestas, $data, array('id' => $propuesta_id) );

    // Si es aprobada, asignar puntos al usuario
    if ($estado_nuevo === 'aprobado') {
        $puntos_actuales = get_user_meta($propuesta->user_id, 'origen_puntos', true);
        $puntos_actuales = $puntos_actuales ? floatval($puntos_actuales) : 0;
        $nuevos_puntos = $puntos_actuales + floatval($propuesta->valor_solicitado);

        update_user_meta($propuesta->user_id, 'origen_puntos', $nuevos_puntos);
    }

    wp_redirect( admin_url('admin.php?page=origen-special-propuestas&msg=updated') );
    exit;
}

// ========================================================================
// 11. PANEL DE ADMINISTRACIÓN
// ========================================================================
add_action( 'admin_menu', 'origen_special_admin_menu' );
function origen_special_admin_menu() {
    add_menu_page( 'Origen SPECIAL', 'Origen SPECIAL', 'manage_options', 'origen-special-users', 'origen_special_admin_page', 'dashicons-leaf', 30 );
    add_submenu_page( 'origen-special-users', 'Propuestas de Canje', 'Propuestas de Canje', 'manage_options', 'origen-special-propuestas', 'origen_special_propuestas_page' );
}

function origen_special_propuestas_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;

    global $wpdb;
    $table_propuestas = $wpdb->prefix . 'origen_propuestas';

    if ( isset($_GET['action']) && isset($_GET['id']) && $_GET['action'] == 'view' ) {
        $id = intval($_GET['id']);
        $propuesta = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table_propuestas WHERE id = %d", $id) );
        if (!$propuesta) {
            echo '<div class="wrap"><h2>Propuesta no encontrada</h2></div>';
            return;
        }

        $user_info = get_userdata($propuesta->user_id);
        $cedula = get_user_meta($propuesta->user_id, 'cedula_cafetera', true);
        $telefono = get_user_meta($propuesta->user_id, 'billing_phone', true); // WooCommerce phone si existe

        $table_fincas = $wpdb->prefix . 'origen_fincas';
        $finca = $wpdb->get_row( $wpdb->prepare("SELECT * FROM $table_fincas WHERE user_id = %d", $propuesta->user_id) );

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">Detalle de Propuesta #<?php echo $propuesta->id; ?></h1>
            <a href="?page=origen-special-propuestas" class="page-title-action">Volver al listado</a>

            <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin-top:20px; border-radius:5px; max-width:800px;">
                <div style="display:flex; justify-content:space-between; margin-bottom:20px; padding-bottom:15px; border-bottom:1px solid #eee;">
                    <div>
                        <h2 style="margin:0;">Estado: <span style="text-transform:uppercase; color: <?php echo ($propuesta->estado == 'aprobado') ? '#10b981' : (($propuesta->estado == 'rechazado') ? '#ef4444' : (($propuesta->estado == 'visita') ? '#3b82f6' : '#eab308')); ?>"><?php echo esc_html($propuesta->estado); ?></span></h2>
                    </div>
                    <div style="text-align:right;">
                        <strong>Fecha de Solicitud:</strong><br><?php echo date('d/m/Y H:i', strtotime($propuesta->fecha)); ?>
                    </div>
                </div>

                <table class="form-table">
                    <tr>
                        <th style="width: 200px;">Caficultor:</th>
                        <td><?php echo esc_html($user_info->display_name); ?> (<?php echo esc_html($user_info->user_email); ?>)<br>
                            Cédula: <?php echo esc_html($cedula); ?><br>
                            Teléfono: <?php echo esc_html($telefono ? $telefono : 'No registrado'); ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Datos de Finca:</th>
                        <td>
                            <?php if ($finca) : ?>
                                <strong>Nombre:</strong> <?php echo esc_html($finca->nombre_finca); ?><br>
                                <strong>Ubicación:</strong> <?php echo esc_html($finca->ubicacion); ?><br>
                                <strong>Hectáreas:</strong> <?php echo floatval($finca->hectareas); ?> ha<br>
                                <strong>Plantas:</strong> <?php echo intval($finca->cantidad_plantas); ?>
                            <?php else : ?>
                                No hay datos de finca registrados.
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Cálculo de Producción (Estimado):</th>
                        <td><strong>$<?php echo number_format($propuesta->valor_produccion, 0, ',', '.'); ?> COP</strong></td>
                    </tr>
                    <tr>
                        <th>Porcentaje Ofrecido:</th>
                        <td><strong><?php echo floatval($propuesta->porcentaje_canje); ?>%</strong></td>
                    </tr>
                    <tr>
                        <th>Valor Solicitado en Puntos:</th>
                        <td style="font-size:18px; color:#10b981;"><strong>$<?php echo number_format($propuesta->valor_solicitado, 0, ',', '.'); ?> COP (Pts)</strong></td>
                    </tr>
                    <tr>
                        <th>Observaciones del Caficultor:</th>
                        <td><?php echo nl2br(esc_html($propuesta->observaciones)); ?></td>
                    </tr>
                </table>

                <?php if ($propuesta->estado === 'pendiente' || $propuesta->estado === 'visita') : ?>
                <hr style="margin:20px 0; border:0; border-top:1px solid #eee;">
                <h3>Acciones del Asesor</h3>
                <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" style="background:#f8fafc; padding:20px; border-radius:8px; border:1px solid #e2e8f0;">
                    <input type="hidden" name="action" value="origen_procesar_propuesta">
                    <input type="hidden" name="propuesta_id" value="<?php echo esc_attr($propuesta->id); ?>">
                    <?php wp_nonce_field('origen_procesar_propuesta_nonce'); ?>

                    <p>
                        <label><strong>Respuesta / Observación para el caficultor:</strong></label><br>
                        <textarea name="respuesta_asesor" rows="3" style="width:100%; margin-top:5px;"></textarea>
                    </p>

                    <div style="margin-top:15px; display:flex; gap:10px;">
                        <button type="submit" name="estado_nuevo" value="aprobado" class="button button-primary" style="background:#10b981; border-color:#059669;" onclick="return confirm('¿Estás seguro de APROBAR esta propuesta? Se le cargarán <?php echo number_format($propuesta->valor_solicitado, 0, ',', '.'); ?> puntos al usuario.');">Aprobar y Cargar Puntos</button>

                        <button type="submit" name="estado_nuevo" value="rechazado" class="button button-primary" style="background:#ef4444; border-color:#dc2626;" onclick="return confirm('¿Estás seguro de RECHAZAR esta propuesta?');">Rechazar</button>

                        <button type="submit" name="estado_nuevo" value="visita" class="button button-secondary">Agendar Visita (Indica en la respuesta)</button>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        <?php
        return;
    }

    // Listado general
    $propuestas = $wpdb->get_results( "SELECT * FROM $table_propuestas ORDER BY fecha DESC" );
    ?>
    <div class="wrap">
        <h1 class="wp-heading-inline">Propuestas Origen SPECIAL</h1>

        <?php if ( isset($_GET['msg']) && $_GET['msg'] == 'updated' ) : ?>
            <div class="notice notice-success is-dismissible"><p>Propuesta actualizada correctamente.</p></div>
        <?php endif; ?>

        <table class="wp-list-table widefat fixed striped" style="margin-top:20px;">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Caficultor</th>
                    <th>Valor Producción</th>
                    <th>Valor Solicitado</th>
                    <th>Porcentaje</th>
                    <th>Estado</th>
                    <th>Fecha</th>
                    <th>Acciones</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($propuestas)) : ?>
                    <tr><td colspan="8">No hay propuestas registradas.</td></tr>
                <?php else: ?>
                    <?php foreach ($propuestas as $p) :
                        $user_info = get_userdata($p->user_id);
                        $name = $user_info ? $user_info->display_name : 'Usuario Desconocido';

                        $estado_color = '#eab308'; // pendiente
                        if ( $p->estado === 'aprobado' ) $estado_color = '#10b981';
                        elseif ( $p->estado === 'rechazado' ) $estado_color = '#ef4444';
                        elseif ( $p->estado === 'visita' ) $estado_color = '#3b82f6';
                    ?>
                    <tr>
                        <td>#<?php echo esc_html($p->id); ?></td>
                        <td><strong><?php echo esc_html($name); ?></strong></td>
                        <td>$<?php echo number_format($p->valor_produccion, 0, ',', '.'); ?></td>
                        <td style="color:#10b981; font-weight:bold;">$<?php echo number_format($p->valor_solicitado, 0, ',', '.'); ?></td>
                        <td><?php echo floatval($p->porcentaje_canje); ?>%</td>
                        <td><span style="background:<?php echo $estado_color; ?>; color:#fff; padding:3px 8px; border-radius:12px; font-size:11px; font-weight:600; text-transform:uppercase;"><?php echo esc_html($p->estado); ?></span></td>
                        <td><?php echo date('d/m/Y', strtotime($p->fecha)); ?></td>
                        <td>
                            <a href="?page=origen-special-propuestas&action=view&id=<?php echo $p->id; ?>" class="button button-small">Ver Detalle</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php
}

function origen_special_admin_page() {
    if ( ! current_user_can( 'manage_options' ) ) return;
    $users = get_users( array( 'role__in' => array( 'caficultor', 'asociacion_cafe', 'comprador_cafe' ), 'orderby' => 'registered', 'order' => 'DESC' ) );
    ?>
    <div class="wrap">
        <h1>Configuración y Gestión Origen SPECIAL</h1>

        <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin: 20px 0; border-radius:5px;">
            <h2>⚙️ Conexión de APIs y Tienda WooCommerce</h2>
            <form method="post" action="options.php">
                <?php settings_fields( 'origen_special_settings_group' ); do_settings_sections( 'origen_special_settings_group' ); ?>
                <table class="form-table">
                    <tr valign="top">
                        <th scope="row">Descuento Tienda (%)<br><small>Descuento aplicado en WooCommerce para rol Caficultor.</small></th>
                        <td><input type="number" name="origen_descuento_caficultor" value="<?php echo esc_attr( get_option('origen_descuento_caficultor', 10) ); ?>" /> %</td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">Precio Base Café (COP/kg)<br><small>Fallback si falla la API de mercado.</small></th>
                        <td><input type="number" name="origen_precio_manual" value="<?php echo esc_attr( get_option('origen_precio_manual', 12000) ); ?>" /></td>
                    </tr>
                    <tr valign="top">
                        <th scope="row">OpenWeatherMap API Key<br><small>Llave para el clima de las fincas.</small></th>
                        <td><input type="text" name="origen_owm_api_key" style="width:350px;" value="<?php echo esc_attr( get_option('origen_owm_api_key') ); ?>" /></td>
                    </tr>
                </table>
                <?php submit_button('Guardar Configuración'); ?>
            </form>
        </div>

        <h2>👥 Usuarios Registrados</h2>
        <table class="wp-list-table widefat fixed striped">
            <thead><tr><th>Nombre</th><th>Cédula</th><th>Rol</th><th>Email</th></tr></thead>
            <tbody>
                <?php foreach ( $users as $user ) {
                    echo '<tr>';
                    echo '<td>' . esc_html( $user->first_name . ' ' . $user->last_name ) . '</td>';
                    echo '<td>' . esc_html( get_user_meta( $user->ID, 'cedula_cafetera', true ) ) . '</td>';
                    echo '<td>' . esc_html( ucfirst( current( $user->roles ) ) ) . '</td>';
                    echo '<td>' . esc_html( $user->user_email ) . '</td>';
                    echo '</tr>';
                } ?>
            </tbody>
        </table>
    </div>
    <?php
}
