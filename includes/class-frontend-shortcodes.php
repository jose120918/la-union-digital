<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Frontend_Shortcodes {

    public function __construct() {
        add_shortcode( 'lud_resumen_ahorro', array( $this, 'render_resumen_ahorro' ) );
        add_shortcode( 'lud_historial', array( $this, 'render_historial_movimientos' ) );
        add_shortcode( 'lud_perfil_datos', array( $this, 'render_perfil_beneficiario' ) ); 
        add_action( 'admin_post_lud_guardar_perfil', array( $this, 'procesar_guardado_perfil' ) );
    }

    // --- CARD 1: RESUMEN ---
    public function render_resumen_ahorro() {
        if ( ! is_user_logged_in() ) return '<div class="lud-alert">Debes iniciar sesión.</div>';

        $user_id = get_current_user_id();
        global $wpdb;
        $datos = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id ) );

        if ( ! $datos ) return '<div class="lud-card"><p>Cuenta no activa. Por favor actualiza tus datos de beneficiario para activar tu ficha.</p></div>';

        // Variables base
        $acciones = intval( $datos->numero_acciones );
        $valor_cuota_ahorro = $acciones * 50000;
        $valor_cuota_secretaria = $acciones * 1000;
        
        // --- 1. CÁLCULO DEUDA ---
        $debe_ahorro = 0; $debe_secretaria = 0; $debe_multa = 0;
        $fecha_ultimo = $datos->fecha_ultimo_aporte ? $datos->fecha_ultimo_aporte : date('Y-m-01'); 
        $inicio = new DateTime( $fecha_ultimo );
        $inicio->modify( 'first day of next month' ); 
        $hoy = new DateTime();
        $cursor = clone $inicio;
        while ( $cursor <= $hoy ) {
            $debe_ahorro += $valor_cuota_ahorro;
            $debe_secretaria += $valor_cuota_secretaria;
            $limite_mes = clone $cursor;
            $limite_mes->setDate( $cursor->format('Y'), $cursor->format('m'), 5 );
            if ( $hoy > $limite_mes ) {
                $dias_tarde = $hoy->diff( $limite_mes )->days;
                $debe_multa += ($dias_tarde * 1000 * $acciones);
            }
            $cursor->modify( 'first day of next month' );
        }
        $total_pendiente = $debe_ahorro + $debe_secretaria + $debe_multa;

        // --- 2. CÁLCULO RENDIMIENTOS DINÁMICO ---
        $anio_actual = date('Y');
        $acumulado_este_anio = $wpdb->get_var( $wpdb->prepare(
            "SELECT SUM(utilidad_asignada) FROM {$wpdb->prefix}fondo_utilidades_mensuales 
             WHERE user_id = %d AND anio = %d", 
            $user_id, $anio_actual
        ));
        
        $rendimientos_totales = floatval($datos->saldo_rendimientos) + floatval($acumulado_este_anio);
        $ahorro_total = number_format( $datos->saldo_ahorro_capital, 0, ',', '.' );
        
        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header">
                <h3>Mi Ahorro</h3>
                <span class="lud-badge <?php echo $total_pendiente > 0 ? 'pendiente' : 'aldia'; ?>">
                    <?php echo $total_pendiente > 0 ? 'Pendiente' : 'Al día'; ?>
                </span>
            </div>

            <div class="lud-balance-section">
                <span class="lud-label">Total Ahorrado</span>
                <span class="lud-amount">$ <?php echo $ahorro_total; ?></span>
            </div>

            <?php if ( $total_pendiente > 0 ): ?>
            <div class="lud-debt-box">
                <h4>⚠️ Tienes pagos pendientes</h4>
                <div class="lud-debt-row"><span>Ahorro:</span><span>$ <?php echo number_format($debe_ahorro); ?></span></div>
                <div class="lud-debt-row"><span>Secretaría:</span><span>$ <?php echo number_format($debe_secretaria); ?></span></div>
                <?php if ($debe_multa > 0): ?><div class="lud-debt-row"><span>Mora:</span><span>$ <?php echo number_format($debe_multa); ?></span></div><?php endif; ?>
                <div class="lud-debt-total"><span>Total a Pagar:</span><span>$ <?php echo number_format($total_pendiente); ?></span></div>
            </div>
            <?php else: ?>
            <div class="lud-success-box">✅ Estás al día con tus aportes.</div>
            <?php endif; ?>

            <div class="lud-details-grid">
                <div class="lud-detail-item">
                    <strong>Mis Acciones Hoy</strong>
                    <span><?php echo $acciones; ?></span>
                </div>
                <div class="lud-detail-item" style="position:relative;">
                    <strong>Rendimientos <?php echo $anio_actual; ?></strong>
                    <span style="color:#1565c0;">$ <?php echo number_format($rendimientos_totales, 0, ',', '.'); ?></span>
                    <?php if($acumulado_este_anio > 0): ?>
                        <small style="display:block; font-size:0.7rem; color:#888;">(Incluye $<?php echo number_format($acumulado_este_anio); ?> acumulados este año)</small>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    // --- CARD 2: HISTORIAL DETALLADO ---
    public function render_historial_movimientos() {
        if ( ! is_user_logged_in() ) return '';

        global $wpdb;
        $user_id = get_current_user_id();
        $tabla_tx = $wpdb->prefix . 'fondo_transacciones';

        $movimientos = $wpdb->get_results( $wpdb->prepare( 
            "SELECT * FROM $tabla_tx WHERE user_id = %d ORDER BY fecha_registro DESC LIMIT 10", 
            $user_id 
        ));

        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header"><h3>Últimos Movimientos</h3></div>
            <?php if ( empty($movimientos) ): ?>
                <div style="text-align:center; padding:20px; color:#777; background:#fafafa; border-radius:8px;">
                    <span style="font-size:2rem;">📭</span><br>No hay movimientos registrados aún.
                </div>
            <?php else: ?>
                <div class="lud-history-list">
                    <?php foreach ( $movimientos as $tx ): 
                        $fecha = date_i18n( 'd M, Y', strtotime( $tx->fecha_registro ) );
                        $monto_total = number_format( $tx->monto, 0, ',', '.' );
                        
                        $status_label = ($tx->estado == 'pendiente') ? '⏳ Revisando...' : (($tx->estado == 'rechazado') ? '❌ Rechazado' : '✅ Aprobado');
                        $status_class = ($tx->estado == 'pendiente') ? 'status-pending' : (($tx->estado == 'rechazado') ? 'status-rejected' : 'status-approved');
                        $icono_main = ($tx->estado == 'pendiente') ? '📤' : (($tx->estado == 'rechazado') ? '🚫' : '💵');

                        $parts = explode( '|| PROCESADO:', $tx->detalle );
                        $nota_raw = str_replace('|', '', isset($parts[0]) ? $parts[0] : '');
                        $desglose_financiero = isset($parts[1]) ? $parts[1] : '';
                        
                        $hubo_excedente = (strpos($desglose_financiero, 'Abono Extra') !== false || strpos($desglose_financiero, 'Excedente') !== false || strpos($desglose_financiero, 'Capital Crédito') !== false);
                        if ( $tx->estado == 'aprobado' && !$hubo_excedente ) {
                            $nota_raw = preg_replace('/Pref: \[.*?\]/', '', $nota_raw);
                        }
                        $nota_final = trim(str_replace(['Pref: [BAJAR CUOTA]', 'Pref: [REDUCIR PLAZO]'], ['<span class="pref-tag">📉 Bajar Cuota</span>', '<span class="pref-tag">⏳ Menos Plazo</span>'], $nota_raw));
                    ?>
                    <div class="lud-history-item">
                        <div class="lud-hist-top">
                            <div class="lud-hist-date-group">
                                <span class="hist-icon"><?php echo $icono_main; ?></span>
                                <div><div class="hist-date"><?php echo $fecha; ?></div><div class="hist-status <?php echo $status_class; ?>"><?php echo $status_label; ?></div></div>
                            </div>
                            <div class="hist-total">$ <?php echo $monto_total; ?></div>
                        </div>
                        <?php if ( !empty($nota_final) ): ?><div class="lud-hist-note"><?php echo $nota_final; ?></div><?php endif; ?>
                        <?php if ( $tx->estado == 'aprobado' && !empty($desglose_financiero) ): ?>
                        <div class="lud-hist-breakdown"><strong>Distribución:</strong><ul><?php foreach(explode(',', $desglose_financiero) as $item): ?><li><?php echo trim($item); ?></li><?php endforeach; ?></ul></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    // --- CARD 3: PERFIL BENEFICIARIO (CORREGIDO) ---
    public function render_perfil_beneficiario() {
        if ( ! is_user_logged_in() ) return '';

        global $wpdb;
        $user_id = get_current_user_id();
        $cuenta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id ) );

        // --- CORRECCIÓN VITAL: Evitar error en usuarios nuevos ---
        if ( ! $cuenta ) {
            $cuenta = new stdClass();
            $cuenta->beneficiario_nombre = '';
            $cuenta->beneficiario_parentesco = '';
            $cuenta->beneficiario_telefono = '';
        }
        // ---------------------------------------------------------

        $msg = '';
        if ( isset( $_GET['lud_profile_saved'] ) ) {
            $msg = '<div class="lud-alert success">✅ Beneficiario actualizado correctamente.</div>';
        }

        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header"><h3>👤 Datos de Beneficiario</h3></div>
            <p style="font-size:0.9rem; color:#666; margin-bottom:15px; background:#e3f2fd; padding:10px; border-radius:8px;">
                <strong>Cumplimiento Art. 22:</strong> En caso de fallecimiento, estos fondos serán entregados a la persona designada.
            </p>
            <?php echo $msg; ?>

            <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST">
                <input type="hidden" name="action" value="lud_guardar_perfil">
                <?php wp_nonce_field( 'lud_perfil_nonce', 'lud_security' ); ?>

                <div class="lud-form-group">
                    <label class="lud-label">Nombre Completo del Beneficiario</label>
                    <input type="text" name="beneficiario_nombre" class="lud-input" 
                           value="<?php echo esc_attr($cuenta->beneficiario_nombre); ?>" 
                           placeholder="Nombre y Apellidos" required>
                </div>

                <div class="lud-form-group">
                    <label class="lud-label">Teléfono de Contacto</label>
                    <input type="text" name="beneficiario_telefono" class="lud-input" 
                           value="<?php echo esc_attr( isset($cuenta->beneficiario_telefono) ? $cuenta->beneficiario_telefono : '' ); ?>" 
                           placeholder="Celular o Fijo" required>
                </div>

                <div class="lud-form-group">
                    <label class="lud-label">Parentesco</label>
                    <select name="beneficiario_parentesco" class="lud-input" required>
                        <option value="">-- Seleccione --</option>
                        <?php 
                        $opciones = ['Esposo(a)', 'Hijo(a)', 'Padre/Madre', 'Hermano(a)', 'Otro'];
                        foreach($opciones as $op): 
                            $selected = ($cuenta->beneficiario_parentesco == $op) ? 'selected' : '';
                        ?>
                            <option value="<?php echo $op; ?>" <?php echo $selected; ?>><?php echo $op; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <button type="submit" class="lud-btn" style="background-color:#546e7a;">Guardar Información</button>
            </form>
        </div>
        <?php
        return ob_get_clean();
    }

    public function procesar_guardado_perfil() {
        if ( ! is_user_logged_in() ) wp_die('No autorizado');
        check_admin_referer( 'lud_perfil_nonce', 'lud_security' );

        global $wpdb;
        $user_id = get_current_user_id();
        $nombre = sanitize_text_field( $_POST['beneficiario_nombre'] );
        $telefono = sanitize_text_field( $_POST['beneficiario_telefono'] );
        $parentesco = sanitize_text_field( $_POST['beneficiario_parentesco'] );

        // Verificar si existe la cuenta
        $existe = $wpdb->get_var( $wpdb->prepare("SELECT COUNT(*) FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id) );

        if ( $existe ) {
            $wpdb->update(
                $wpdb->prefix . 'fondo_cuentas',
                array( 
                    'beneficiario_nombre' => $nombre, 
                    'beneficiario_parentesco' => $parentesco,
                    'beneficiario_telefono' => $telefono
                ),
                array( 'user_id' => $user_id )
            );
        } else {
            // INSERTAR NUEVA CUENTA SI NO EXISTE
            $wpdb->insert(
                $wpdb->prefix . 'fondo_cuentas',
                array(
                    'user_id' => $user_id,
                    'numero_acciones' => 0, // Inicia en 0 hasta que pague
                    'beneficiario_nombre' => $nombre,
                    'beneficiario_parentesco' => $parentesco,
                    'beneficiario_telefono' => $telefono,
                    'estado_socio' => 'activo'
                )
            );
        }

        wp_redirect( add_query_arg( 'lud_profile_saved', '1', wp_get_referer() ) );
        exit;
    }
}