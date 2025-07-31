<?php
/**
 * Helper para Redsys - Funciones principales
 */

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

    error_log("URL de Redsys: " . $redsys_url);
    error_log("Pedido: " . $pedido);
    error_log("Importe: " . $importe);

    // ✅ NUEVO ENFOQUE: SCRIPT QUE SE EJECUTA INMEDIATAMENTE
    $html = '
    <div id="redsys-overlay" style="position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.8);display:flex;align-items:center;justify-content:center;z-index:99999;">
        <div style="background:white;padding:30px;border-radius:10px;text-align:center;max-width:400px;">
            <h3 style="margin:0 0 20px 0;color:#333;">Redirigiendo al banco...</h3>
            <div style="margin:20px 0;">⏳ Por favor, espere...</div>
            <p style="font-size:14px;color:#666;margin:20px 0 0 0;">Será redirigido automáticamente a la pasarela de pago segura.</p>
        </div>
    </div>
    <form id="formulario_redsys" action="' . $redsys_url . '" method="POST">
        <input type="hidden" name="Ds_SignatureVersion" value="' . $version . '">
        <input type="hidden" name="Ds_MerchantParameters" value="' . $params . '">
        <input type="hidden" name="Ds_Signature" value="' . $signature . '">
    </form>
    <script>
        console.log("🏦 Ejecutando redirección inmediata a Redsys...");
        console.log("URL destino: ' . $redsys_url . '");
        console.log("Pedido: ' . $pedido . '");
        console.log("Importe: ' . $importe . ' céntimos");
        
        // ✅ EJECUTAR INMEDIATAMENTE SIN TIMEOUT
        (function() {
            var form = document.getElementById("formulario_redsys");
            if (form) {
                console.log("✅ Formulario encontrado, enviando...");
                form.submit();
            } else {
                console.error("❌ No se encontró el formulario");
                alert("Error: No se pudo inicializar el pago. Refresca la página e inténtalo de nuevo.");
                // Eliminar overlay en caso de error
                var overlay = document.getElementById("redsys-overlay");
                if (overlay) overlay.remove();
            }
        })();
    </script>';

    guardar_datos_pedido($pedido, $reserva_data);
    return $html;
}

function is_production_environment() {
    // ✅ CAMBIAR A TRUE PARA ACTIVAR PRODUCCIÓN
    return false; // ← CAMBIO: false = PRUEBAS, true = PRODUCCIÓN
}

function guardar_datos_pedido($order_id, $reserva_data) {
    error_log('=== GUARDANDO DATOS DEL PEDIDO ===');
    error_log("Order ID: $order_id");
    error_log("Datos a guardar: " . print_r($reserva_data, true));
    
    // ✅ GUARDAR EN MÚLTIPLES UBICACIONES CON MÁS PERSISTENCIA
    
    // 1. Sesión
    if (!session_id()) {
        session_start();
    }
    
    if (!isset($_SESSION['pending_orders'])) {
        $_SESSION['pending_orders'] = array();
    }
    
    $_SESSION['pending_orders'][$order_id] = array(
        'reservation_data' => $reserva_data,
        'timestamp' => time(),
        'status' => 'pending'
    );
    
    // 2. Transients con múltiples claves
    set_transient('redsys_order_' . $order_id, $reserva_data, 7200); // 2 horas
    set_transient('latest_pending_order', array(
        'order_id' => $order_id,
        'data' => $reserva_data,
        'timestamp' => time()
    ), 7200);
    
    // 3. Options temporales (más persistente)
    update_option('temp_order_' . $order_id, $reserva_data, false);
    update_option('latest_order_data', array(
        'order_id' => $order_id,
        'data' => $reserva_data,
        'timestamp' => time()
    ), false);
    
    // ✅ 4. NUEVO: Guardar en base de datos como respaldo
    global $wpdb;
    $table_config = $wpdb->prefix . 'reservas_configuration';
    
    $wpdb->replace(
        $table_config,
        array(
            'config_key' => 'pending_order_' . $order_id,
            'config_value' => json_encode($reserva_data),
            'config_group' => 'temp_orders',
            'description' => 'Datos temporales de pedido pendiente'
        )
    );
    
    error_log("✅ Datos del pedido $order_id guardados en múltiples ubicaciones");
}

function process_successful_payment($order_id, $params) {
    error_log('=== PROCESANDO PAGO EXITOSO ===');
    error_log("Order ID: $order_id");
    error_log("Params: " . print_r($params, true));
    
    // ✅ MEJORAR RECUPERACIÓN DE DATOS - MÚLTIPLES MÉTODOS
    $reservation_data = null;
    
    // Método 1: Transient
    $reservation_data = get_transient('redsys_order_' . $order_id);
    error_log("Datos desde transient: " . ($reservation_data ? 'ENCONTRADOS' : 'NO ENCONTRADOS'));
    
    // Método 2: Session
    if (!$reservation_data) {
        if (!session_id()) {
            session_start();
        }
        if (isset($_SESSION['pending_orders'][$order_id])) {
            $reservation_data = $_SESSION['pending_orders'][$order_id]['reservation_data'];
            error_log("Datos desde sesión: ENCONTRADOS");
        }
    }
    
    // Método 3: Option temporal
    if (!$reservation_data) {
        $reservation_data = get_option('temp_order_' . $order_id);
        error_log("Datos desde option: " . ($reservation_data ? 'ENCONTRADOS' : 'NO ENCONTRADOS'));
    }
    
    // ✅ Método 4: NUEVO - Desde base de datos
    if (!$reservation_data) {
        global $wpdb;
        $table_config = $wpdb->prefix . 'reservas_configuration';
        
        $config_value = $wpdb->get_var($wpdb->prepare(
            "SELECT config_value FROM $table_config WHERE config_key = %s",
            'pending_order_' . $order_id
        ));
        
        if ($config_value) {
            $reservation_data = json_decode($config_value, true);
            error_log("Datos desde BD: ENCONTRADOS");
        } else {
            error_log("Datos desde BD: NO ENCONTRADOS");
        }
    }
    
    // ✅ Método 5: ÚLTIMO RECURSO - Buscar el más reciente
    if (!$reservation_data) {
        $latest = get_option('latest_order_data');
        if ($latest && (time() - $latest['timestamp']) < 3600) { // Si es de la última hora
            $reservation_data = $latest['data'];
            error_log("Datos desde latest_order_data: ENCONTRADOS");
        }
    }
    
    if (!$reservation_data) {
        error_log('❌ CRÍTICO: No se encontraron datos de reserva para pedido: ' . $order_id);
        return false;
    }

    error_log('✅ Datos de reserva recuperados: ' . print_r($reservation_data, true));

    try {
        // ✅ USAR EL PROCESADOR EXISTENTE PERO CON DATOS CORRECTOS
        if (!class_exists('ReservasProcessor')) {
            require_once RESERVAS_PLUGIN_PATH . 'includes/class-reservas-processor.php';
        }

        $processor = new ReservasProcessor();
        
        // ✅ PREPARAR DATOS CORRECTAMENTE PARA EL PROCESADOR
        $processed_data = array(
            'nombre' => $reservation_data['nombre'] ?? '',
            'apellidos' => $reservation_data['apellidos'] ?? '',
            'email' => $reservation_data['email'] ?? '',
            'telefono' => $reservation_data['telefono'] ?? '',
            'reservation_data' => json_encode($reservation_data),
            'metodo_pago' => 'redsys',
            'transaction_id' => $params['Ds_AuthorisationCode'] ?? '',
            'order_id' => $order_id
        );

        error_log('✅ Datos preparados para procesador: ' . print_r($processed_data, true));

        // ✅ PROCESAR LA RESERVA
        $result = $processor->process_reservation_payment($processed_data);
        
        if ($result['success']) {
            error_log('✅ Reserva procesada exitosamente: ' . $result['data']['localizador']);
            
            // ✅ GUARDAR MÚLTIPLES COPIAS DE LOS DATOS PARA LA CONFIRMACIÓN
            if (!session_id()) {
                session_start();
            }
            $_SESSION['confirmed_reservation'] = $result['data'];
            
            // Guardar también en transients con múltiples claves
            set_transient('confirmed_reservation_' . $order_id, $result['data'], 7200);
            set_transient('latest_confirmed_reservation', $result['data'], 7200);
            set_transient('confirmed_by_localizador_' . $result['data']['localizador'], $result['data'], 7200);
            
            // Options temporales
            update_option('temp_confirmed_' . $order_id, $result['data'], false);
            update_option('latest_confirmed_reservation', $result['data'], false);
            
            // ✅ GUARDAR TAMBIÉN EN BD
            global $wpdb;
            $table_config = $wpdb->prefix . 'reservas_configuration';
            
            $wpdb->replace(
                $table_config,
                array(
                    'config_key' => 'confirmed_order_' . $order_id,
                    'config_value' => json_encode($result['data']),
                    'config_group' => 'confirmed_orders',
                    'description' => 'Datos de pedido confirmado'
                )
            );
            
            error_log('✅ Datos de confirmación guardados en múltiples ubicaciones');
            
            // Limpiar datos temporales
            delete_transient('redsys_order_' . $order_id);
            delete_option('temp_order_' . $order_id);
            $wpdb->delete($table_config, array('config_key' => 'pending_order_' . $order_id));
            
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