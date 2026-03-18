jQuery(document).ready(function($) {

    // ==========================================
    // 1. TABS GENERALES (LOGIN/REGISTRO Y SIMULADOR)
    // ==========================================
    $('.origen-tab-btn').on('click', function(e) {
        e.preventDefault();
        const btn = $(this);
        const targetId = btn.data('target');
        const wrapper = btn.closest('.origen-auth-box, .origen-calc-wrapper');

        if(!wrapper.length) return;

        // Limpiar estados activos
        wrapper.find('.origen-tab-btn').removeClass('active');
        wrapper.find('.origen-form').hide();

        // Activar la pestaña actual
        btn.addClass('active');

        // Mostrar el formulario destino
        const formId = targetId.includes('calc') ? 'origen-' + targetId + '-form' : 'origen-' + targetId + '-form';
        $('#' + formId).fadeIn(300);
    });

    // ==========================================
    // MOSTRAR FORMULARIOS DE AUTENTICACIÓN
    // ==========================================
    $('#btn-show-auth').on('click', function(e) {
        e.preventDefault();
        $('.origen-landing-hero').fadeOut(300, function() {
            $('#origen-auth-box').fadeIn(300);

            // Si quieres activar el tab de registro automáticamente
            $('.origen-tab-btn[data-target="register"]').click();
        });
    });

    // ==========================================
    // 2. TOGGLE CONTRASEÑA
    // ==========================================
    $('.toggle-pass').on('click', function() {
        const input = $(this).prev('input');
        const icon = $(this).find('i');

        if (input.attr('type') === 'password') {
            input.attr('type', 'text');
            icon.removeClass('ph-eye').addClass('ph-eye-slash');
        } else {
            input.attr('type', 'password');
            icon.removeClass('ph-eye-slash').addClass('ph-eye');
        }
    });

    // ==========================================
    // 3. CONDICIONALES DE ROLES Y MUNICIPIOS
    // ==========================================
    $('#reg_role').on('change', function() {
        $('.origen-conditional-fields').hide();
        if($(this).val() === 'caficultor') $('#cond_caficultor').show();
        if($(this).val() === 'asociacion_cafe') $('#cond_asociacion').show();
        if($(this).val() === 'comprador_cafe') $('#cond_comprador').show();
    });

    $('#reg_asoc_lista').on('change', function() {
        if($(this).val() === 'otra') {
            $('#wrap_otra_asoc').show();
        } else {
            $('#wrap_otra_asoc').hide();
        }
    });

    const municipiosData = {
        "Cauca": ["Popayán", "Piendamó", "El Tambo", "Inzá", "Páez", "Caldono", "Silvia", "Timbío"],
        "Huila": ["Pitalito", "Garzón", "Neiva", "La Plata", "San Agustín"],
        "Nariño": ["Pasto", "La Unión", "Buesaco", "Sandoná"],
        "Antioquia": ["Medellín", "Andes", "Ciudad Bolívar", "Jericó"]
    };

    $('#reg_depto').on('change', function() {
        const depto = $(this).val();
        const muniSelect = $('#reg_muni');
        muniSelect.empty().append('<option value="">Seleccione...</option>');

        if(municipiosData[depto]) {
            $.each(municipiosData[depto], function(i, muni) {
                muniSelect.append('<option value="'+muni+'">'+muni+'</option>');
            });
        }
    });

    // ==========================================
    // 4. FUNCIONES AJAX SEGURAS (JQUERY)
    // ==========================================
    function showMsg(containerId, text, type) {
        const box = $('#' + containerId);
        if(!box.length) return;
        box.text(text).removeClass('error success loading').addClass('origen-msg ' + type).css('display', 'block');
    }

    function doAjax(action, dataObj, btn, msgBoxId, successCallback) {
        showMsg(msgBoxId, 'Procesando...', 'loading');
        btn.prop('disabled', true);

        // Agregamos action y nonce a los datos
        dataObj.action = action;
        dataObj.nonce = origenApp.nonce;

        $.ajax({
            url: origenApp.ajax_url,
            type: 'POST',
            data: dataObj,
            dataType: 'json',
            success: function(res) {
                btn.prop('disabled', false);
                if(res.success) {
                    successCallback(res);
                } else {
                    showMsg(msgBoxId, res.data, 'error');
                }
            },
            error: function() {
                btn.prop('disabled', false);
                showMsg(msgBoxId, 'Error de conexión con el servidor.', 'error');
            }
        });
    }

    // LOGIN
    $('#origen-login-form').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        const data = {
            user: $('#log_user').val(),
            pass: $('#log_pass').val()
        };

        doAjax('origen_login_action', data, btn, 'origen-msg', function(res) {
            window.location.replace(origenApp.dashboard_url);
        });
    });

    // REGISTRO
    $('#origen-register-form').on('submit', function(e) {
        e.preventDefault();
        const pass = $('#reg_pass').val();

        if(pass.length < 8) {
            showMsg('origen-msg', 'Contraseña mínima de 8 caracteres.', 'error');
            return;
        }

        const btn = $(this).find('button[type="submit"]');
        const data = {
            name: $('#reg_name').val(),
            lastname: $('#reg_lastname').val(),
            id_number: $('#reg_id_number').val(),
            role: $('#reg_role').val(),
            depto: $('#reg_depto').val(),
            muni: $('#reg_muni').val(),
            email: $('#reg_email').val(),
            pass: pass,
            finca: $('#reg_finca').val() || '',
            tamano_prod: $('#reg_tamano_prod').val() || '',
            asoc_lista: $('#reg_asoc_lista').val() || '',
            asoc_otra: $('#reg_asoc_otra').val() || '',
            tipo_comprador: $('#reg_tipo_comprador').val() || ''
        };

        doAjax('origen_register_action', data, btn, 'origen-msg', function(res) {
            window.location.replace(origenApp.dashboard_url);
        });
    });

    // GUARDAR FINCA
    $('#origen-finca-form').on('submit', function(e) {
        e.preventDefault();
        const btn = $(this).find('button[type="submit"]');
        const data = {
            f_nombre: $('#finca_nombre').val(),
            f_ubicacion: $('#finca_ubicacion').val(),
            f_altura: $('#finca_altura').val(),
            f_hectareas: $('#finca_hectareas').val(),
            f_plantas: $('#finca_plantas').val(),
            f_densidad: $('#finca_densidad').val(),
            f_variedad: $('#finca_variedad').val(),
            f_edad: $('#finca_edad').val(),
            f_sombra: $('#finca_sombra').val(),
            f_sistema: $('#finca_sistema').val()
        };

        doAjax('origen_save_finca_action', data, btn, 'origen-finca-msg', function(res) {
            showMsg('origen-finca-msg', res.data, 'success');
            setTimeout(() => { $('#origen-finca-msg').fadeOut(); }, 3000);
        });
    });

    // ==========================================
    // 5. NAVEGACIÓN SPA DEL DASHBOARD (CORREGIDA)
    // ==========================================
    $('.nav-trigger').on('click', function(e) {
        e.preventDefault();
        const targetId = $(this).data('target');

        // Ocultar absolutamente todas las vistas principales para evitar solapamientos
        $('.origen-main-view').hide();

        // Mostrar solo la vista solicitada
        $('#' + targetId).fadeIn(300);

        // Si entra al simulador, disparar el cálculo inicial
        if(targetId === 'origen-view-produccion') {
            calcularSimuladorEnVivo();
        }
    });

    // ==========================================
    // 6. MOTOR DE CÁLCULO BASE (AJAX A BD)
    // ==========================================
    $('#btn-calcular-cosecha').on('click', function(e) {
        e.preventDefault();
        const btn = $(this);
        const boxResults = $('#origen-calc-results');

        boxResults.hide();
        doAjax('origen_calculate_production', {}, btn, 'origen-calc-msg', function(res) {
            $('#origen-calc-msg').hide();

            // Inyectar valores financieros
            $('#res-kg').text(res.data.kg);
            $('#res-sacos').text(res.data.sacos);
            $('#res-valor').text(res.data.valor);

            // Inyectar rendimiento
            $('#res-indicador').text(res.data.indicador).css('background-color', res.data.color);
            $('#res-bar').css({ 'width': '0%', 'background-color': res.data.color });

            // Inyectar Clima
            if(res.data.clima) {
                $('#weather-text').html(res.data.clima.temp + '°C, ' + res.data.clima.descripcion + '<br><small>Humedad aire: ' + res.data.clima.humedad + '%</small>');
            } else {
                $('#weather-text').html('<small>No disponible</small>');
            }

            boxResults.fadeIn(400);
            setTimeout(() => { $('#res-bar').css('width', res.data.porcentaje + '%'); }, 100);
        });
    });

    // ==========================================
    // 7. SIMULADOR INTERACTIVO (EN VIVO)
    // ==========================================
    if($('#sim_precio').length && typeof origenApp !== 'undefined' && origenApp.precio_actual) {
        $('#sim_precio').val(origenApp.precio_actual);
    }

    function calcularSimuladorEnVivo() {
        if(!$('#sim_ha').length) return;

        // Actualizar etiquetas de los rangos
        $('#val-sim-ha').text( $('#sim_ha').val() );
        $('#val-sim-densidad').text( parseInt($('#sim_densidad').val()).toLocaleString('es-CO') );
        $('#val-sim-humedad').text( $('#sim_humedad').val() + '%' );
        $('#val-sim-perdidas').text( $('#sim_perdidas').val() + '%' );

        const ha = parseFloat($('#sim_ha').val()) || 0;
        const densidad = parseInt($('#sim_densidad').val()) || 0;
        const plantasTotales = ha * densidad;

        const factorVar = parseFloat($('#sim_variedad').val()) || 1;
        const factorEdad = parseFloat($('#sim_edad').val()) || 1;
        const factorFert = parseFloat($('#sim_fertilizacion').val()) || 1;

        const humedad = parseInt($('#sim_humedad').val()) || 0;
        const perdidas = parseInt($('#sim_perdidas').val()) || 0;
        const hintHumedad = $('#hint-humedad');

        const precio = parseFloat($('#sim_precio').val()) || 0;
        const costoPorKg = parseFloat($('#sim_costo').val()) || 0;

        // 1. Producción Base Teórica (aprox. 1.2kg por planta en condiciones óptimas)
        const rendimientoBasePlanta = 1.2;
        let produccion_bruta_kg = plantasTotales * rendimientoBasePlanta * factorVar * factorEdad * factorFert;

        // 2. Merma por humedad
        let castigoHumedad = 0;
        if(humedad > 12) {
            castigoHumedad = (humedad - 12) * 0.015;
            hintHumedad.text('Humedad alta: Merma de ' + Math.round(castigoHumedad*100) + '% al secar.').css('color', 'var(--danger)');
        } else {
            hintHumedad.text('Humedad óptima CPS. Sin merma.').css('color', 'var(--primary-color)');
        }

        // 3. Merma por plagas/enfermedades
        const castigoPlagas = perdidas / 100;

        // 4. Producción Neta
        let produccion_neta_kg = produccion_bruta_kg * (1 - castigoHumedad) * (1 - castigoPlagas);
        const sacos = produccion_neta_kg / 60;

        // 5. Finanzas
        const ingresoBruto = produccion_neta_kg * precio;
        const costoTotal = produccion_neta_kg * costoPorKg;
        const utilidadNeta = ingresoBruto - costoTotal;

        let margen = 0;
        if(ingresoBruto > 0) {
            margen = (utilidadNeta / ingresoBruto) * 100;
        }

        // 6. Pintar resultados
        $('#sim-res-kg').text( produccion_neta_kg.toLocaleString('es-CO', {maximumFractionDigits: 1}) );
        $('#sim-res-sacos').text( sacos.toLocaleString('es-CO', {maximumFractionDigits: 1}) );

        $('#sim-res-ingreso').text( '$' + ingresoBruto.toLocaleString('es-CO', {maximumFractionDigits: 0}) );
        $('#sim-res-costo').text( '$' + costoTotal.toLocaleString('es-CO', {maximumFractionDigits: 0}) );

        $('#sim-res-utilidad').html('$' + Math.abs(utilidadNeta).toLocaleString('es-CO', {maximumFractionDigits: 0}) + ' <small style="font-size:14px; color:var(--text-muted); font-weight:400;">COP</small>');

        // 7. Colorear indicador de rentabilidad
        const badge = $('#sim-res-margen-badge');
        badge.text(Math.round(margen) + '% Margen');
        if (margen >= 20) {
            badge.css('background-color', '#10b981'); // Verde
            $('#sim-res-utilidad').css('color', '#10b981');
        } else if (margen >= 0) {
            badge.css('background-color', '#eab308'); // Amarillo
            $('#sim-res-utilidad').css('color', '#eab308');
        } else {
            badge.css('background-color', '#ef4444'); // Rojo (Pérdidas)
            $('#sim-res-utilidad').css('color', '#ef4444');
            if(utilidadNeta < 0) $('#sim-res-utilidad').prepend('-');
        }
    }

    // Escuchar cualquier cambio en los sliders para recalcular en vivo
    $('#sim_ha, #sim_densidad, #sim_variedad, #sim_edad, #sim_fertilizacion, #sim_humedad, #sim_perdidas, #sim_precio, #sim_costo').on('input change', calcularSimuladorEnVivo);

    // Forzar calculo al cambiar a la pestaña de simulador
    $('.origen-tab-btn[data-target="calc-sim"]').on('click', function(){
        setTimeout(calcularSimuladorEnVivo, 100);
    });

    // ==========================================
    // 8. TIENDA WOOCOMMERCE: SINCRONIZAR CANTIDAD
    // ==========================================
    // Escucha cuando el usuario cambia el input de cantidad
    $('.origen-qty-input').on('input change', function() {
        let val = parseInt($(this).val());

        // Evitar números negativos o cero
        if (isNaN(val) || val < 1) {
            val = 1;
            $(this).val(1);
        }

        // Obtener el ID del producto vinculado
        const targetBtnId = $(this).data('target-btn');

        // Actualizar el atributo data-quantity del botón de WooCommerce
        $('#btn-add-' + targetBtnId).attr('data-quantity', val);
    });

});