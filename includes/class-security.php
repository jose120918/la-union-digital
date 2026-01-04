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
        if ( ! is_user_logged_in() ) {
            wp_die( '⛔ Acceso denegado: inicia sesión para ver el archivo.' );
        }

        // 1. Validar parámetro entrante
        if ( ! isset( $_GET['file'] ) ) wp_die( 'Archivo no especificado.' );

        $ruta_param = sanitize_text_field( wp_unslash( $_GET['file'] ) );
        $segmentos = array_filter( array_map( 'sanitize_file_name', explode( '/', $ruta_param ) ) );
        $ruta_relativa = implode( '/', $segmentos );

        if ( empty( $ruta_relativa ) ) {
            wp_die( 'Ruta de archivo no válida.' );
        }

        // 2. Construir rutas normalizadas y blindadas contra traversal
        $upload_dir = wp_upload_dir();
        $base_dir = trailingslashit( wp_normalize_path( $upload_dir['basedir'] . '/fondo_seguro/' ) );
        $file_path = wp_normalize_path( $base_dir . $ruta_relativa );

        if ( ! file_exists( $file_path ) || strpos( $file_path, $base_dir ) !== 0 ) {
            wp_die( '❌ El archivo no existe o ha sido eliminado.' );
        }

        // 3. Validar permisos: directiva siempre puede ver; socios solo sus archivos
        $usuario_actual = get_current_user_id();
        $es_directiva = current_user_can( 'manage_options' ) || current_user_can( 'lud_manage_tesoreria' ) || current_user_can( 'lud_view_tesoreria' );

        if ( ! $es_directiva ) {
            global $wpdb;
            $es_comprobante = $this->usuario_posee_comprobante( $usuario_actual, $ruta_relativa );
            $es_documento = (bool) $wpdb->get_var( $wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d AND url_documento_id = %s",
                $usuario_actual,
                basename( $ruta_relativa )
            ) );

            if ( ! $es_comprobante && ! $es_documento ) {
                wp_die( '⛔ Acceso denegado a este archivo protegido.' );
            }
        }

        // 4. Servir el archivo en línea
        $mime = wp_check_filetype( basename( $file_path ) );
        $tipo_mime = ! empty( $mime['type'] ) ? $mime['type'] : 'application/octet-stream';

        header( 'Content-Type: ' . $tipo_mime );
        header( 'Content-Length: ' . filesize( $file_path ) );
        header( 'Content-Disposition: inline; filename="' . basename( $file_path ) . '"' );
        readfile( $file_path );
        exit;
    }

    /**
     * Verifica si el usuario es propietario de algún comprobante que incluya la ruta solicitada.
     */
    private function usuario_posee_comprobante( $user_id, $ruta_relativa ) {
        global $wpdb;
        $tabla = $wpdb->prefix . 'fondo_transacciones';

        $coincide = (bool) $wpdb->get_var( $wpdb->prepare(
            "SELECT COUNT(*) FROM $tabla WHERE user_id = %d AND (comprobante_url = %s OR comprobante_url LIKE %s)",
            $user_id,
            $ruta_relativa,
            '%' . $wpdb->esc_like( $ruta_relativa ) . '%'
        ) );

        if ( $coincide ) {
            return true;
        }

        $todos = $wpdb->get_col( $wpdb->prepare( "SELECT comprobante_url FROM $tabla WHERE user_id = %d", $user_id ) );
        foreach ( $todos as $comp ) {
            $segmentos = $this->dividir_comprobantes( $comp );
            if ( in_array( $ruta_relativa, $segmentos, true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Divide múltiples rutas de comprobante separadas por "|".
     */
    private function dividir_comprobantes( $comprobante_url ) {
        $partes = array_filter( array_map( 'trim', explode( '|', (string) $comprobante_url ) ) );
        $rutas = array();

        foreach ( $partes as $parte ) {
            $segmentos = array_filter( array_map( 'sanitize_file_name', explode( '/', $parte ) ) );
            if ( empty( $segmentos ) ) {
                continue;
            }
            $rutas[] = implode( '/', $segmentos );
        }

        return $rutas;
    }
}
