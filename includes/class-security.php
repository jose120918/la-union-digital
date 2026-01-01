<?php
/**
 * Seguridad y control de acceso a archivos sensibles.
 *
 * Esta clase expone un endpoint seguro para entregar comprobantes alojados en
 * la carpeta protegida del plugin sin exponer rutas directas.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Security {

    /**
     * Registra los hooks necesarios para servir comprobantes bajo control.
     */
    public function __construct() {
        // Hook para servir la imagen de forma segura
        add_action( 'admin_post_lud_ver_comprobante', array( $this, 'servir_imagen_segura' ) );
    }

    /**
     * Entrega un archivo almacenado en la carpeta segura si el usuario tiene permisos.
     */
    public function servir_imagen_segura() {
        // 1. Verificar Permisos (Solo Admin/Tesorero puede ver esto)
        if ( ! current_user_can( 'manage_options' ) && ! current_user_can( 'lud_manage_tesoreria' ) && ! current_user_can( 'lud_view_tesoreria' ) ) {
            wp_die( '⛔ Acceso Denegado: Documento Privado.' );
        }

        // 2. Validar que piden un archivo
        if ( ! isset( $_GET['file'] ) ) wp_die( 'Archivo no especificado.' );

        $filename = sanitize_file_name( $_GET['file'] );
        
        // 3. Construir la ruta segura
        $upload_dir = wp_upload_dir();
        $base_dir = $upload_dir['basedir'] . '/fondo_seguro/';
        $file_path = $base_dir . $filename;

        // 4. Verificar que el archivo existe y está dentro de la carpeta segura (evitar hackeos de ruta)
        if ( ! file_exists( $file_path ) || realpath( $file_path ) === false || strpos( realpath( $file_path ), realpath( $base_dir ) ) !== 0 ) {
            wp_die( '❌ El archivo no existe o ha sido eliminado.' );
        }

        // 5. Servir la imagen (Engañar al navegador para que crea que es un archivo público)
        $mime = wp_check_filetype( $file_path );
        header( 'Content-Type: ' . $mime['type'] );
        header( 'Content-Length: ' . filesize( $file_path ) );
        readfile( $file_path );
        exit;
    }
}
