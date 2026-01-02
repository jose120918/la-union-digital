<?php
/**
 * Módulo de retiros voluntarios según estatutos.
 *
 * Expone un shortcode para que el socio solicite su retiro, valida paz y salvo,
 * registra la solicitud y calcula el monto estimado a entregar en la siguiente reunión.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Module_Retiros {

    /**
     * Registra el shortcode y asegura la tabla de retiros.
     */
    public function __construct() {
        add_shortcode( 'lud_retiro_voluntario', array( $this, 'render_formulario_retiro' ) );
        add_action( 'admin_post_lud_solicitar_retiro', array( $this, 'procesar_solicitud_retiro' ) );

        $this->crear_tabla_retiros();
    }

    /**
     * Crea o actualiza la tabla de retiros (compatibilidad con instalaciones previas).
     */
    private function crear_tabla_retiros() {
        global $wpdb;
        $tabla = "{$wpdb->prefix}fondo_retiros";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $charset = $wpdb->get_charset_collate();
        $sql = "CREATE TABLE $tabla (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT(20) UNSIGNED NOT NULL,
            monto_estimado DECIMAL(15,2) NOT NULL DEFAULT 0,
            detalle TEXT NULL,
            estado ENUM('pendiente','aprobado','rechazado','pagado') DEFAULT 'pendiente',
            fecha_solicitud DATETIME DEFAULT CURRENT_TIMESTAMP,
            fecha_respuesta DATETIME NULL,
            usuario_respuesta BIGINT(20) UNSIGNED NULL,
            motivo_respuesta TEXT NULL,
            PRIMARY KEY (id),
            KEY user_id (user_id)
        ) $charset;";
        dbDelta( $sql ); // dbDelta agrega columnas faltantes si la tabla ya existe.
    }

    /**
     * Renderiza el formulario de retiro cumpliendo Art. 14 y disposición de intereses.
     */
    public function render_formulario_retiro() {
        if ( ! is_user_logged_in() ) return '<p class="lud-alert error">Debes iniciar sesión para solicitar retiro.</p>';

        global $wpdb;
        $user_id = get_current_user_id();
        $cuenta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id ) );
        if ( ! $cuenta ) return '<div class="lud-card"><p>No tienes ficha activa en el fondo.</p></div>';

        // Validar estado del socio
        if ( $cuenta->estado_socio === 'retirado' ) {
            return '<div class="lud-card lud-success-box">✅ Ya registramos tu retiro. Si necesitas reingresar, puedes solicitarlo dos meses después del retiro.</div>';
        }

        // Verificar si hay solicitud pendiente
        $pendiente = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fondo_retiros WHERE user_id = %d AND estado = 'pendiente'", $user_id ) );
        if ( $pendiente > 0 ) {
            return '<div class="lud-card"><div class="lud-alert">📬 Tu solicitud de retiro está en revisión. La entrega se hará en la siguiente reunión si hay liquidez.</div></div>';
        }

        // Calcular deudas para paz y salvo
        $info_deuda = LUD_Module_Transacciones::calcular_deuda_usuario_estatico( $user_id );
        if ( ! $info_deuda ) return '<div class="lud-card"><p>No se encontró información financiera.</p></div>';

        $deuda_total = $info_deuda['total_admin'] + $info_deuda['creditos'];
        if ( $deuda_total > 0 ) {
            $mensaje_deuda = "<div class='lud-alert error'><strong>⚠️ No cumples paz y salvo.</strong><br>Debes saldar <b>$".number_format($deuda_total, 0, ',', '.')."</b> antes de solicitar el retiro (Art. 14).</div>";
            return "<div class='lud-card'>$mensaje_deuda</div>";
        }

        // Calcular rendimientos acumulados
        $anio_actual = date( 'Y' );
        $rendimientos_anio = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(utilidad_asignada) FROM {$wpdb->prefix}fondo_utilidades_mensuales WHERE user_id = %d AND anio = %d",
            $user_id,
            $anio_actual
        ) );
        $rendimientos_totales = floatval( $cuenta->saldo_rendimientos ) + floatval( $rendimientos_anio );
        $monto_estimado = floatval( $cuenta->saldo_ahorro_capital ) + $rendimientos_totales;

        $mensaje_estado = '';
        if ( isset( $_GET['lud_status'] ) && $_GET['lud_status'] === 'retiro_enviado' ) {
            $mensaje_estado = '<div class="lud-alert success">✅ Solicitud enviada. La asamblea revisará y programará la entrega en la próxima reunión con disponibilidad de fondos.</div>';
        } elseif ( isset( $_GET['lud_error'] ) ) {
            $mensaje_estado = '<div class="lud-alert error">❌ ' . esc_html( $_GET['lud_error'] ) . '</div>';
        }

        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header">
                <h3>Solicitud de Retiro</h3>
            </div>

            <?php echo $mensaje_estado; ?>

            <div class="lud-success-box lud-success-compacta" style="margin-bottom:15px;">
                <span class="lud-icono-estado" aria-hidden="true">💡</span>
                <div>
                    <div class="lud-success-titulo">Entrega en reunión con liquidez</div>
                    <div class="lud-success-texto">Se devolverá el ahorro más intereses en la siguiente reunión con disponibilidad (Art. 14 y disposiciones finales).</div>
                </div>
            </div>

            <div class="lud-details-grid" style="margin-bottom:15px;">
                <div class="lud-detail-item">
                    <strong>Ahorro acumulado</strong>
                    <span>$ <?php echo number_format( $cuenta->saldo_ahorro_capital, 0, ',', '.' ); ?></span>
                </div>
                <div class="lud-detail-item">
                    <strong>Intereses generados</strong>
                    <span>$ <?php echo number_format( $rendimientos_totales, 0, ',', '.' ); ?></span>
                </div>
                <div class="lud-detail-item">
                    <strong>Total estimado a devolver</strong>
                    <span style="color:#2e7d32;">$ <?php echo number_format( $monto_estimado, 0, ',', '.' ); ?></span>
                </div>
            </div>

            <form action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" method="POST">
                <input type="hidden" name="action" value="lud_solicitar_retiro">
                <?php wp_nonce_field( 'lud_retiro_nonce', 'lud_seguridad' ); ?>
                <input type="hidden" name="monto_estimado" value="<?php echo esc_attr( $monto_estimado ); ?>">

                <div class="lud-form-group">
                    <label class="lud-label">Motivo del retiro</label>
                    <textarea name="detalle" class="lud-input" rows="3" placeholder="Ej: Cambio de residencia, necesidad personal..." required></textarea>
                </div>

                <label class="lud-checkbox-moderno">
                    <input type="checkbox" name="aceptacion_reglamento" value="1" required>
                    <span class="lud-checkbox-caja" aria-hidden="true"></span>
                    <span class="lud-checkbox-texto">Acepto que podré solicitar reingreso a la asamblea dos meses después del retiro.</span>
                </label>

                <button type="submit" class="lud-btn">Enviar solicitud</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Procesa la solicitud de retiro y la registra en la tabla dedicada.
     */
    public function procesar_solicitud_retiro() {
        if ( ! isset( $_POST['lud_seguridad'] ) || ! wp_verify_nonce( $_POST['lud_seguridad'], 'lud_retiro_nonce' ) ) wp_die( 'Seguridad' );
        if ( ! is_user_logged_in() ) wp_die( 'Debes iniciar sesión.' );

        global $wpdb;
        $user_id = get_current_user_id();
        $monto_estimado = floatval( $_POST['monto_estimado'] );
        $detalle = sanitize_textarea_field( $_POST['detalle'] );
        $acepta = isset( $_POST['aceptacion_reglamento'] );

        // Validar aceptación
        if ( ! $acepta ) {
            wp_redirect( add_query_arg( 'lud_error', 'Debes aceptar las condiciones del retiro.', wp_get_referer() ) );
            exit;
        }

        // Revalidar paz y salvo en backend
        $info_deuda = LUD_Module_Transacciones::calcular_deuda_usuario_estatico( $user_id );
        if ( $info_deuda && ( $info_deuda['total_admin'] + $info_deuda['creditos'] ) > 0 ) {
            wp_redirect( add_query_arg( 'lud_error', 'No estás paz y salvo. Cancela tus obligaciones antes de solicitar retiro.', wp_get_referer() ) );
            exit;
        }

        // Revisar créditos activos
        $credito_activo = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fondo_creditos WHERE user_id = %d AND estado IN ('activo','mora','pendiente_tesoreria','pendiente_deudor')", $user_id ) );
        if ( $credito_activo > 0 ) {
            wp_redirect( add_query_arg( 'lud_error', 'No puedes retirarte con créditos vigentes. Liquida tus obligaciones.', wp_get_referer() ) );
            exit;
        }

        // Evitar duplicados pendientes
        $pendiente = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->prefix}fondo_retiros WHERE user_id = %d AND estado = 'pendiente'", $user_id ) );
        if ( $pendiente > 0 ) {
            wp_redirect( add_query_arg( 'lud_error', 'Ya tienes una solicitud en trámite.', wp_get_referer() ) );
            exit;
        }

        $wpdb->insert(
            "{$wpdb->prefix}fondo_retiros",
            array(
                'user_id'        => $user_id,
                'monto_estimado' => $monto_estimado,
                'detalle'        => $detalle,
                'estado'         => 'pendiente',
                'fecha_solicitud'=> current_time( 'mysql' )
            ),
            array( '%d', '%f', '%s', '%s', '%s' )
        );

        // Disparar notificación de nueva solicitud de retiro.
        do_action( 'lud_evento_retiro', $user_id, 'pendiente', $monto_estimado, $detalle );

        wp_redirect( add_query_arg( 'lud_status', 'retiro_enviado', remove_query_arg( array( 'lud_error' ), wp_get_referer() ) ) );
        exit;
    }
}
