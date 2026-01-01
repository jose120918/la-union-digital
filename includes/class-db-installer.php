<?php
/**
 * Instalador de las tablas principales del plugin.
 *
 * Este archivo crea todas las tablas necesarias para almacenar cuentas, créditos,
 * transacciones y utilidades del Fondo La Unión. Se ejecuta en la activación del plugin.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_DB_Installer {

    /**
     * Crea o actualiza las tablas personalizadas usando dbDelta.
     */
    public static function install() {
        global $wpdb;
        $charset_collate = $wpdb->get_charset_collate();

        $sql = [];

        // 1. Cuentas Maestras (CORREGIDO: Sin duplicados)
        $sql[] = "CREATE TABLE {$wpdb->prefix}fondo_cuentas (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            numero_acciones INT(3) NOT NULL DEFAULT 0,
            saldo_ahorro_capital DECIMAL(15,2) DEFAULT 0.00,
            saldo_rendimientos DECIMAL(15,2) DEFAULT 0.00,
            fecha_ultimo_aporte DATE NULL,
            deuda_secretaria DECIMAL(15,2) DEFAULT 0.00,
            
            /* --- ESTADO DEL SOCIO --- */
            estado_socio ENUM('activo', 'suspendido', 'retirado', 'pendiente', 'rechazado') DEFAULT 'pendiente',
            
            /* --- DATOS BENEFICIARIO --- */
            beneficiario_nombre VARCHAR(255) NULL,
            beneficiario_parentesco VARCHAR(50) NULL,
            beneficiario_telefono VARCHAR(20) NULL,

            /* --- DATOS PERSONALES --- */
            tipo_documento VARCHAR(20) NULL,
            numero_documento VARCHAR(50) NULL,
            fecha_nacimiento DATE NULL,
            direccion_residencia TEXT NULL,
            ciudad_pais VARCHAR(100) NULL,
            telefono_contacto VARCHAR(20) NULL,
            email_contacto VARCHAR(100) NULL,

            /* --- INFORMACIÓN FONDO --- */
            fecha_ingreso_fondo DATE NULL,
            aporte_inicial DECIMAL(15,2) DEFAULT 0.00,

            /* --- INFORMACIÓN FINANCIERA --- */
            actividad_economica TEXT NULL,
            origen_fondos TEXT NULL,
            banco_medio_pago VARCHAR(100) NULL,

            /* --- DOCUMENTOS --- */
            url_documento_id TEXT NULL,
            
            permite_galeria TINYINT(1) DEFAULT 0 COMMENT '0=Camara, 1=Archivo',
            PRIMARY KEY  (id),
            UNIQUE KEY user_id (user_id)
        ) $charset_collate;";

        // 2. Transacciones (Intacto)
        $sql[] = "CREATE TABLE {$wpdb->prefix}fondo_transacciones (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            tipo ENUM('pago_consolidado','aporte','cuota_credito','multa','gasto_operativo','ajuste_redondeo') NOT NULL,
            monto DECIMAL(15,2) NOT NULL,
            metodo_pago VARCHAR(50) DEFAULT 'efectivo',
            comprobante_url VARCHAR(255) NULL,
            estado ENUM('pendiente', 'aprobado', 'rechazado') DEFAULT 'pendiente',
            aprobado_por BIGINT(20) UNSIGNED NULL, 
            fecha_registro DATETIME DEFAULT CURRENT_TIMESTAMP,
            fecha_aprobacion DATETIME NULL,
            detalle TEXT NULL,
            KEY user_id (user_id)
        ) $charset_collate;";

        // 3. Créditos (Intacto)
        $sql[] = "CREATE TABLE {$wpdb->prefix}fondo_creditos (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            tipo_credito ENUM('corriente', 'agil') NOT NULL,
            monto_solicitado DECIMAL(15,2) NOT NULL,
            monto_aprobado DECIMAL(15,2) DEFAULT 0,
            codigo_seguimiento VARCHAR(20) NULL,
            plazo_meses INT(3) NOT NULL,
            tasa_interes DECIMAL(4,2) NOT NULL,
            cuota_estimada DECIMAL(15,2) NOT NULL,
            deudor_solidario_id BIGINT(20) UNSIGNED NULL,
            firma_solicitante VARCHAR(255) NULL,
            firma_deudor VARCHAR(255) NULL,
            ip_registro VARCHAR(45) NULL,
            user_agent TEXT NULL,
            fecha_aprobacion_deudor DATETIME NULL,
            datos_entrega TEXT NULL,
            saldo_actual DECIMAL(15,2) DEFAULT 0,
            estado ENUM('pendiente_deudor', 'pendiente_tesoreria', 'activo', 'rechazado', 'pagado', 'mora') DEFAULT 'pendiente_deudor',
            fecha_solicitud DATETIME DEFAULT CURRENT_TIMESTAMP,
            fecha_aprobacion DATETIME NULL,
            detalle_rechazo TEXT NULL,
            KEY user_id (user_id)
        ) $charset_collate;";

        // 4. Tabla de Amortización (Intacto)
        $sql[] = "CREATE TABLE {$wpdb->prefix}fondo_amortizacion (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            credito_id BIGINT(20) UNSIGNED NOT NULL,
            numero_cuota INT(3) NOT NULL,
            fecha_vencimiento DATE NOT NULL,
            capital_programado DECIMAL(15,2) NOT NULL,
            interes_programado DECIMAL(15,2) NOT NULL,
            valor_cuota_total DECIMAL(15,2) NOT NULL,
            fecha_pago DATE NULL,
            monto_pagado DECIMAL(15,2) DEFAULT 0,
            estado ENUM('pendiente', 'pagado', 'parcial', 'mora') DEFAULT 'pendiente',
            KEY credito_id (credito_id)
        ) $charset_collate;";

        // 5. Gastos Operativos (Intacto)
        $sql[] = "CREATE TABLE {$wpdb->prefix}fondo_gastos (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            categoria VARCHAR(50) NOT NULL,
            descripcion TEXT NOT NULL,
            monto DECIMAL(15,2) NOT NULL,
            comprobante_url VARCHAR(255) NULL,
            registrado_por BIGINT(20) UNSIGNED NOT NULL,
            fecha_gasto DATETIME DEFAULT CURRENT_TIMESTAMP
        ) $charset_collate;";

        // 6. Desglose Financiero (Intacto)
        $sql[] = "CREATE TABLE {$wpdb->prefix}fondo_recaudos_detalle (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            transaccion_id BIGINT(20) UNSIGNED NOT NULL,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            concepto ENUM('ahorro', 'multa', 'cuota_secretaria', 'interes_credito', 'capital_credito', 'excedente') NOT NULL,
            monto DECIMAL(15,2) NOT NULL,
            fecha_recaudo DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY transaccion_id (transaccion_id),
            KEY concepto (concepto)
        ) $charset_collate;";

        // 7. Utilidades Mensuales (Intacto)
        $sql[] = "CREATE TABLE {$wpdb->prefix}fondo_utilidades_mensuales (
            id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            mes INT(2) NOT NULL,
            anio INT(4) NOT NULL,
            acciones_mes INT(3) NOT NULL,
            utilidad_asignada DECIMAL(15,2) DEFAULT 0,
            estado ENUM('provisional', 'liquidado') DEFAULT 'provisional',
            fecha_calculo DATETIME DEFAULT CURRENT_TIMESTAMP,
            KEY user_mes (user_id, mes, anio)
        ) $charset_collate;";

        require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
        foreach ( $sql as $query ) {
            dbDelta( $query );
        }
    }
}
