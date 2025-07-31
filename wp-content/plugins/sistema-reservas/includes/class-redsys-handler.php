<?php
require_once __DIR__ . '/redsys-api.php';

function generar_formulario_redsys($reserva_data) {
    error_log('=== INICIANDO GENERACIÓN FORMULARIO REDSYS ===');
    error_log('Datos recibidos: ' . print_r($reserva_data, true));
    
    $miObj = new RedsysAPI();

    // ✅ CONFIGURACIÓN PARA PRUEBAS
    if (is_production_environment()) {
        $clave = 'Q+2780shKFbG3vkPXS2+kY6RWQLQnWD9';
        $codigo_comercio = '014591697';
        $terminal = '001';
        error_log('🟢 USANDO CONFIGURACIÓN DE PRODUCCIÓN');
    } else {
        $clave = 'sq7HjrUOBfKmC576ILgskD5srU870gJ7';
        $codigo_comercio = '999008881';
        $terminal = '001';
        error_log('🟡 USANDO CONFIGURACIÓN DE PRUEBAS');
    }
    
    $total_price = null;
    if (isset($reserva_data['total_price'])) {
        $total_price = $reserva_data['total_price'];
    } elseif (isset($reserva_data['precio_final'])) {
        $total_price = $reserva_data['precio_final'];
    }
    
    if ($total_price) {
        $total_price = str_replace(['€', ' ', ','], ['', '', '.'], $total_price);
        $total_price = floatval($total_price);
    }
    
    if (!$total_price || $total_price <= 0) {
        throw new Exception('El importe debe ser mayor que 0. Recibido: ' . $total_price);
    }
    
    $importe = intval($total_price * 100);
    
    $timestamp = time();
    $random = rand(100, 999);
    $pedido = date('ymdHis') . str_pad($random, 3, '0', STR_PAD_LEFT);
    
    if (strlen($pedido) > 12) {
        $pedido = substr($pedido, 0, 12);
    }
    
    $miObj->setParameter("DS_MERCHANT_AMOUNT", $importe);
    $miObj->setParameter("DS_MERCHANT_ORDER", $pedido);
    $miObj->setParameter("DS_MERCHANT_MERCHANTCODE", $codigo_comercio);
    $miObj->setParameter("DS_MERCHANT_CURRENCY", "978");
    $miObj->setParameter("DS_MERCHANT_TRANSACTIONTYPE", "0");
    $miObj->setParameter("DS_MERCHANT_TERMINAL", $terminal);
    
    $base_url = home_url();
    $miObj->setParameter("DS_MERCHANT_MERCHANTURL", $base_url . '/wp-admin/admin-ajax.php?action=redsys_notification');
    $miObj->setParameter("DS_MERCHANT_URLOK", $base_url . '/confirmacion-reserva/?status=ok&order=' . $pedido);
    $miObj->setParameter("DS_MERCHANT_URLKO", $base_url . '/error-pago/?status=ko&order=' . $pedido);
    
    $descripcion = "Reserva Medina Azahara - " . ($reserva_data['fecha'] ?? date('Y-m-d'));
    $miObj->setParameter("DS_MERCHANT_PRODUCTDESCRIPTION", $descripcion);
    
    if (isset($reserva_data['nombre']) && isset($reserva_data['apellidos'])) {
        $miObj->setParameter("DS_MERCHANT_TITULAR", $reserva_data['nombre'] . ' ' . $reserva_data['apellidos']);
    }

    $params = $miObj->createMerchantParameters();
    $signature = $miObj->createMerchantSignature($clave);
    $version = "HMAC_SHA256_V1";

    $redsys_url = is_production_environment() ? 
        'https://sis.redsys.es/sis/realizarPago' :
        'https://sis-t.redsys.es:25443/sis/realizarPago';

    // ✅ FORMULARIO LIMPIO
    $html = '<div id="redsys-redirect-container">';
    $html .= '<div style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;z-index:99999;">';
    $html .= '<div style="background:white;padding:30px;border-radius:10px;text-align:center;max-width:400px;">';
    $html .= '<h3 style="margin:0 0 20px 0;color:#333;">Redirigiendo al banco...</h3>';
    $html .= '<div style="margin:20px 0;">⏳ Por favor, espere...</div>';
    $html .= '<p style="font-size:14px;color:#666;margin:20px 0 0 0;">Será redirigido automáticamente a la pasarela de pago segura.</p>';
    $html .= '</div></div>';
    $html .= '<form id="formulario_redsys" action="' . $redsys_url . '" method="POST" style="display:none;">';
    $html .= '<input type="hidden" name="Ds_SignatureVersion" value="' . $version . '">';
    $html .= '<input type="hidden" name="Ds_MerchantParameters" value="' . $params . '">';
    $html .= '<input type="hidden" name="Ds_Signature" value="' . $signature . '">';
    $html .= '</form>';
    $html .= '<script type="text/javascript">';
    $html .= 'setTimeout(function() {';
    $html .= 'var form = document.getElementById("formulario_redsys");';
    $html .= 'if(form) { form.submit(); } else { alert("Error inicializando pago"); }';
    $html .= '}, 1000);';
    $html .= '</script>';
    $html .= '</div>';

    guardar_datos_pedido($pedido, $reserva_data);
    return $html;
}

function is_production_environment() {
    return false; // PRUEBAS
}

function process_successful_payment($order_id, $params) {
    error_log('=== PROCESANDO PAGO EXITOSO CON REDSYS ===');
    error_log("Order ID: $order_id");
    error_log("Params: " . print_r($params, true));
    
    // Recuperar datos de la reserva
    $reservation_data = get_transient('redsys_order_' . $order_id);
    
    if (!$reservation_data) {
        if (!session_id()) {
            session_start();
        }
        $reservation_data = $_SESSION['pending_orders'][$order_id]['reservation_data'] ?? null;
    }
    
    if (!$reservation_data) {
        error_log('❌ No se encontraron datos de reserva para pedido: ' . $order_id);
        return false;
    }

    try {
        // Procesar la reserva usando tu sistema existente
        if (!class_exists('ReservasProcessor')) {
            require_once RESERVAS_PLUGIN_PATH . 'includes/class-reservas-processor.php';
        }

        $processor = new ReservasProcessor();
        
        // Preparar datos para el procesador
        $processed_data = array(
            'nombre' => $reservation_data['nombre'] ?? '',
            'apellidos' => $reservation_data['apellidos'] ?? '',
            'email' => $reservation_data['email'] ?? '',
            'telefono' => $reservation_data['telefono'] ?? '',
            'reservation_data' => json_encode($reservation_data),
            'metodo_pago' => 'redsys',
            'transaction_id' => $params['Ds_AuthorisationCode'] ?? '',
            'order_id' => $order_id // ✅ AÑADIR ESTO
        );

        // Procesar la reserva usando el método existente
        $result = $processor->process_reservation_payment($processed_data);
        
        if ($result['success']) {
            error_log('✅ Reserva procesada exitosamente: ' . $result['data']['localizador']);
            
            // ✅ GUARDAR DATOS PARA LA PÁGINA DE CONFIRMACIÓN DE MÚLTIPLES FORMAS
            if (!session_id()) {
                session_start();
            }
            
            // 1. Guardar en sesión
            $_SESSION['confirmed_reservation'] = $result['data'];
            
            // 2. Guardar en transient con el order_id
            set_transient('confirmed_reservation_' . $order_id, $result['data'], 3600);
            
            // 3. Guardar con el localizador también
            set_transient('confirmed_reservation_loc_' . $result['data']['localizador'], $result['data'], 3600);
            
            // 4. Guardar como última reserva confirmada
            set_transient('latest_confirmed_reservation', $result['data'], 1800); // 30 minutos
            
            // 5. Guardar en BD temporal para mayor seguridad
            update_option('temp_reservation_' . $order_id, $result['data'], false);
            update_option('temp_reservation_loc_' . $result['data']['localizador'], $result['data'], false);
            
            error_log('✅ Datos de confirmación guardados en múltiples ubicaciones');
            error_log('- Localizador: ' . $result['data']['localizador']);
            error_log('- Order ID: ' . $order_id);
            
            // Limpiar datos temporales
            delete_transient('redsys_order_' . $order_id);
            if (isset($_SESSION['pending_orders'][$order_id])) {
                unset($_SESSION['pending_orders'][$order_id]);
            }
            
            return true;
        } else {
            error_log('❌ Error procesando reserva: ' . $result['message']);
            return false;
        }
        
    } catch (Exception $e) {
        error_log('❌ Excepción procesando pago exitoso: ' . $e->getMessage());
        return false;
    }
}

function get_reservation_data_for_confirmation() {
    error_log('=== INTENTANDO RECUPERAR DATOS DE CONFIRMACIÓN ===');
    
    // ✅ Método 1: Desde URL (order_id)
    if (isset($_GET['order']) && !empty($_GET['order'])) {
        $order_id = sanitize_text_field($_GET['order']);
        error_log('Order ID desde URL: ' . $order_id);
        
        // Buscar en transients
        $data = get_transient('confirmed_reservation_' . $order_id);
        if ($data) {
            error_log('✅ Datos encontrados en transient por order_id');
            return $data;
        }
        
        // Buscar en options temporales
        $data = get_option('temp_reservation_' . $order_id);
        if ($data) {
            error_log('✅ Datos encontrados en options por order_id');
            // Limpiar después de usar
            delete_option('temp_reservation_' . $order_id);
            return $data;
        }
    }
    
    // ✅ Método 2: Desde sesión
    if (!session_id()) {
        session_start();
    }
    
    if (isset($_SESSION['confirmed_reservation'])) {
        error_log('✅ Datos encontrados en sesión');
        $data = $_SESSION['confirmed_reservation'];
        // Limpiar sesión después de usar
        unset($_SESSION['confirmed_reservation']);
        return $data;
    }
    
    // ✅ Método 3: Buscar la reserva más reciente del último minuto
    global $wpdb;
    $table_reservas = $wpdb->prefix . 'reservas_reservas';
    
    $recent_reservation = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM $table_reservas 
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 2 MINUTE)
         AND metodo_pago = 'redsys'
         ORDER BY created_at DESC 
         LIMIT 1"
    ));
    
    if ($recent_reservation) {
        error_log('✅ Reserva reciente encontrada en BD: ' . $recent_reservation->localizador);
        
        return array(
            'localizador' => $recent_reservation->localizador,
            'reserva_id' => $recent_reservation->id,
            'detalles' => array(
                'fecha' => $recent_reservation->fecha,
                'hora' => $recent_reservation->hora,
                'personas' => $recent_reservation->total_personas,
                'precio_final' => $recent_reservation->precio_final
            )
        );
    }
    
    error_log('❌ No se encontraron datos de confirmación por ningún método');
    return null;
}

