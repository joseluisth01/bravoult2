<?php
/**
 * Helper para Redsys - Funciones principales
 */

require_once __DIR__ . '/redsys-api.php';

function generar_formulario_redsys($reserva_data) {
        error_log('=== INICIANDO GENERACIÓN FORMULARIO REDSYS ===');
    error_log('Datos recibidos: ' . print_r($reserva_data, true));
    
    $miObj = new RedsysAPI();

    // ✅ CONFIGURACIÓN ACTUALIZADA PARA PRODUCCIÓN
    if (is_production_environment()) {
        // DATOS DE PRODUCCIÓN
        $clave = 'Q+2780shKFbG3vkPXS2+kY6RWQLQnWD9'; // ✅ TU NUEVA CLAVE DE PRODUCCIÓN
        $codigo_comercio = '014591697'; // Tu código FUC (debería ser el mismo)
        $terminal = '001'; // Tu terminal (debería ser el mismo)
        error_log('🟢 USANDO CONFIGURACIÓN DE PRODUCCIÓN');
    } else {
        // DATOS DE PRUEBAS (mantener los antiguos para desarrollo)
        $clave = 'sq7HjrUOBfKmC576ILgskD5srU870gJ7';
        $codigo_comercio = '014591697';
        $terminal = '001';
        error_log('🟡 USANDO CONFIGURACIÓN DE PRUEBAS');
    }
    
    // ✅ MEJORAR EL MANEJO DEL IMPORTE
    $total_price = null;
    if (isset($reserva_data['total_price'])) {
        $total_price = $reserva_data['total_price'];
    } elseif (isset($reserva_data['precio_final'])) {
        $total_price = $reserva_data['precio_final'];
    }
    
    // Limpiar el precio (quitar €, espacios, etc.)
    if ($total_price) {
        $total_price = str_replace(['€', ' ', ','], ['', '', '.'], $total_price);
        $total_price = floatval($total_price);
    }
    
    error_log("Total price procesado: " . $total_price);
    
    if (!$total_price || $total_price <= 0) {
        error_log("❌ ERROR: Importe inválido - " . $total_price);
        throw new Exception('El importe debe ser mayor que 0. Recibido: ' . $total_price);
    }
    
    // Convertir importe a céntimos (Redsys trabaja en céntimos)
    $importe = intval($total_price * 100);
    error_log("Importe en céntimos para Redsys: " . $importe);
    
    // ✅ GENERAR NÚMERO DE PEDIDO ÚNICO
    $timestamp = time();
    $random = rand(1000, 9999);
    
    // Generar pedido con formato más robusto
    $pedido = date('ymd') . sprintf('%06d', $timestamp % 1000000);
    
    // Asegurar que tenga exactamente 12 caracteres
    if (strlen($pedido) > 12) {
        $pedido = substr($pedido, 0, 12);
    } elseif (strlen($pedido) < 12) {
        $pedido = str_pad($pedido, 12, '0', STR_PAD_LEFT);
    }
    
    error_log("Número de pedido generado: " . $pedido);
    
    // Verificar que todos los datos son correctos antes de continuar
    if (empty($codigo_comercio) || empty($terminal) || empty($clave)) {
        throw new Exception('Faltan datos de configuración de Redsys');
    }
    
    // Configurar parámetros del pedido
    $miObj->setParameter("DS_MERCHANT_AMOUNT", $importe);
    $miObj->setParameter("DS_MERCHANT_ORDER", $pedido);
    $miObj->setParameter("DS_MERCHANT_MERCHANTCODE", $codigo_comercio);
    $miObj->setParameter("DS_MERCHANT_CURRENCY", "978"); // EUR
    $miObj->setParameter("DS_MERCHANT_TRANSACTIONTYPE", "0"); // Autorización
    $miObj->setParameter("DS_MERCHANT_TERMINAL", $terminal);
    
    // URLs de respuesta - IMPORTANTE: Estas URLs deben existir en tu WordPress
    $base_url = home_url();
    $miObj->setParameter("DS_MERCHANT_MERCHANTURL", $base_url . '/wp-admin/admin-ajax.php?action=redsys_notification');
    $miObj->setParameter("DS_MERCHANT_URLOK", $base_url . '/confirmacion-reserva/?status=ok&order=' . $pedido);
    $miObj->setParameter("DS_MERCHANT_URLKO", $base_url . '/error-pago/?status=ko&order=' . $pedido);
    
    // Información adicional
    $descripcion = "Reserva Medina Azahara - " . ($reserva_data['fecha'] ?? date('Y-m-d'));
    $miObj->setParameter("DS_MERCHANT_PRODUCTDESCRIPTION", $descripcion);
    
    // Datos del titular (opcional pero recomendado)
    if (isset($reserva_data['nombre']) && isset($reserva_data['apellidos'])) {
        $miObj->setParameter("DS_MERCHANT_TITULAR", $reserva_data['nombre'] . ' ' . $reserva_data['apellidos']);
    }

    // ✅ LOGGING DETALLADO DE PARÁMETROS
    error_log("=== PARÁMETROS ENVIADOS A REDSYS ===");
    error_log("DS_MERCHANT_AMOUNT: " . $importe);
    error_log("DS_MERCHANT_ORDER: " . $pedido);
    error_log("DS_MERCHANT_MERCHANTCODE: " . $codigo_comercio);
    error_log("DS_MERCHANT_TERMINAL: " . $terminal);

    // Generar parámetros y firma
    $params = $miObj->createMerchantParameters();
    $signature = $miObj->createMerchantSignature($clave);
    $version = "HMAC_SHA256_V1";

    error_log("Parámetros codificados: " . $params);
    error_log("Firma generada: " . $signature);

    $redsys_url = is_production_environment() ? 
        'https://sis.redsys.es/sis/realizarPago' :        // ✅ PRODUCCIÓN
        'https://sis-t.redsys.es:25443/sis/realizarPago'; // PRUEBAS
    
    error_log("URL de Redsys: " . $redsys_url);

    // Generar formulario HTML que se auto-envía
    $html = '<form id="formulario_redsys" action="' . $redsys_url . '" method="POST">';
    $html .= '<input type="hidden" name="Ds_SignatureVersion" value="' . $version . '">';
    $html .= '<input type="hidden" name="Ds_MerchantParameters" value="' . $params . '">';
    $html .= '<input type="hidden" name="Ds_Signature" value="' . $signature . '">';
    $html .= '</form>';
    
    // JavaScript para auto-enviar el formulario inmediatamente
    $html .= '<script>';
    $html .= 'console.log("Enviando formulario a Redsys...");';
    $html .= 'console.log("Importe: ' . $importe . ' céntimos (' . $total_price . ' euros)");';
    $html .= 'console.log("Pedido: ' . $pedido . '");';
    $html .= 'document.getElementById("formulario_redsys").submit();';
    $html .= '</script>';

    // Guardar datos del pedido para verificación posterior
    guardar_datos_pedido($pedido, $reserva_data);

    error_log("✅ Formulario HTML generado correctamente");
    return $html;
}

function is_production_environment() {
    // ✅ CAMBIAR ESTO: Detectar si estamos en producción
    $site_url = site_url();
    
    error_log("Site URL: " . $site_url);
    
    // Detectar si es producción (NO contiene palabras de desarrollo)
    $is_prod = !strpos($site_url, 'localhost') && 
               !strpos($site_url, '.local') && 
               !strpos($site_url, 'dev.') &&
               !strpos($site_url, 'staging.') &&
               !strpos($site_url, 'test.');
    
    // ✅ FORZAR A PRODUCCIÓN SI EL DOMINIO ES EL REAL
    if (strpos($site_url, 'autobusmedinaazahara.com') !== false) {
        $is_prod = true;
    }
    
    error_log("Es producción: " . ($is_prod ? 'SÍ' : 'NO'));
    
    return $is_prod;
}

function guardar_datos_pedido($order_id, $reserva_data) {
    // Guardar en sesión para verificar después
    if (!session_id()) {
        session_start();
    }
    
    // Guardar en sesión
    if (!isset($_SESSION['pending_orders'])) {
        $_SESSION['pending_orders'] = array();
    }
    
    $_SESSION['pending_orders'][$order_id] = array(
        'reservation_data' => $reserva_data,
        'timestamp' => time(),
        'status' => 'pending'
    );
    
    // También guardar en transient de WordPress por seguridad
    set_transient('redsys_order_' . $order_id, $reserva_data, 3600); // 1 hora
    
    error_log("✅ Datos del pedido $order_id guardados para verificación posterior");
}

function process_successful_payment($order_id, $params) {
    error_log('=== PROCESANDO PAGO EXITOSO ===');
    error_log("Order ID: $order_id");
    
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
            'order_id' => $order_id
        );

        // Procesar la reserva usando el método existente
        $result = $processor->process_reservation_payment($processed_data);
        
        if ($result['success']) {
            error_log('✅ Reserva procesada exitosamente: ' . $result['data']['localizador']);
            
            // Guardar datos para la página de confirmación
            if (!session_id()) {
                session_start();
            }
            $_SESSION['confirmed_reservation'] = $result['data'];
            
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