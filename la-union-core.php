<?php
/*
Plugin Name: Sistema La Unión Digital
Description: Core financiero y gestión de socios para el Fondo de Inversión.
Version: 1.2.0
Author: Jose Muñoz
*/

if ( ! defined( 'ABSPATH' ) ) exit;

// Definir constantes de rutas
define( 'LUD_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'LUD_PLUGIN_URL', plugin_dir_url( __FILE__ ) );

// Includes de clases
require_once LUD_PLUGIN_DIR . 'includes/class-db-installer.php';
require_once LUD_PLUGIN_DIR . 'includes/class-security.php';
require_once LUD_PLUGIN_DIR . 'includes/class-module-transacciones.php';
require_once LUD_PLUGIN_DIR . 'includes/class-module-creditos.php';
require_once LUD_PLUGIN_DIR . 'includes/class-frontend-shortcodes.php';
require_once LUD_PLUGIN_DIR . 'includes/class-admin-tesoreria.php';
require_once LUD_PLUGIN_DIR . 'includes/class-debug-tools.php';

// Inicialización
function lud_init_plugin() {
    new LUD_Security();
    new LUD_Module_Transacciones();
    new LUD_Module_Creditos();
    new LUD_Frontend_Shortcodes();
    new LUD_Admin_Tesoreria();
    new LUD_Debug_Tools(); // Solo visible para Admin (update_core)
}
add_action( 'plugins_loaded', 'lud_init_plugin' );

// Activación (Base de datos y Roles)
register_activation_hook( __FILE__, array( 'LUD_DB_Installer', 'install' ) );
register_activation_hook( __FILE__, 'lud_create_roles' );

// Función mejorada de Roles
function lud_create_roles() {
    // 1. ROL SOCIO (Para listas de deudores y acceso frontend)
    add_role( 'lud_socio', '👤 Socio La Unión', array(
        'read' => true,
        'level_0' => true // Acceso básico
    ));

    // 2. ROL SECRETARIA (Solo Lectura)
    add_role( 'lud_secretaria', '📝 Secretaria', array(
        'read' => true,
        'lud_view_tesoreria' => true,   // PUEDE VER
        'lud_manage_tesoreria' => false // NO PUEDE TOCAR DINERO
    ));

    // 3. ROL TESORERO (Control Total Operativo)
    add_role( 'lud_tesorero', '💰 Tesorero(a)', array(
        'read' => true,
        'upload_files' => true,         // Subir comprobantes
        'lud_view_tesoreria' => true,
        'lud_manage_tesoreria' => true  // Permiso de aprobar/rechazar
    ));

    // 4. ROL PRESIDENTE (Control Total Supervisión)
    add_role( 'lud_presidente', '🏛️ Presidente', array(
        'read' => true,
        'lud_view_tesoreria' => true,
        'lud_manage_tesoreria' => true
    ));

    // 5. Asegurar que el ADMINISTRADOR también tenga estos permisos
    $admin = get_role('administrator');
    $admin->add_cap('lud_view_tesoreria');
    $admin->add_cap('lud_manage_tesoreria');
}

// Estilos (Frontend y Backend para consistencia visual)
function lud_enqueue_assets() {
    wp_enqueue_style( 'lud-main-style', LUD_PLUGIN_URL . 'assets/css/lud-style.css', array(), '1.2.0' );
}
add_action( 'wp_enqueue_scripts', 'lud_enqueue_assets' ); // Frontend

// NUEVO: Cargar estilos también en el Admin para que la Tesorera vea las Cards bonitas
function lud_admin_enqueue_assets() {
    wp_enqueue_style( 'lud-admin-style', LUD_PLUGIN_URL . 'assets/css/lud-style.css', array(), '1.2.0' );
}

add_action( 'admin_enqueue_scripts', 'lud_admin_enqueue_assets' );