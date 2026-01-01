<?php
/**
 * Módulo de reporte de pagos y control de deuda del socio.
 *
 * Provee el formulario público para subir comprobantes y la lógica de validación
 * y registro de transacciones desde el frontend.
 */
if ( ! defined( 'ABSPATH' ) ) exit;

class LUD_Module_Transacciones {

    /**
     * Registra los shortcodes y endpoints de procesamiento de pagos.
     */
    public function __construct() {
        add_shortcode( 'lud_reportar_pago', array( $this, 'render_form_pago' ) );
        add_action( 'admin_post_lud_procesar_pago', array( $this, 'procesar_pago' ) );
        add_action( 'admin_post_nopriv_lud_procesar_pago', array( $this, 'procesar_pago' ) ); 
    }

    /**
     * Helper: Calcula la deuda exacta del usuario al día de hoy.
     * Centraliza la lógica para usarla tanto en el formulario (Frontend) como en la validación (Backend).
     *
     * @param int $user_id ID del usuario socio.
     * @return array|false Arreglo con desglose de deudas o false si la cuenta no existe.
     */
    public function calcular_deuda_usuario( $user_id ) {
        global $wpdb;
        $cuenta = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$wpdb->prefix}fondo_cuentas WHERE user_id = %d", $user_id ) );
        
        if ( ! $cuenta ) return false;

        $acciones = intval( $cuenta->numero_acciones );
        $valor_cuota_ahorro = $acciones * 50000;
        $valor_cuota_secretaria = $acciones * 1000;
        
        $debe_ahorro = 0;
        $debe_secretaria = 0;
        $debe_multa = 0;

        // --- 1. DEUDA ADMINISTRATIVA ---
        $fecha_ultimo = $cuenta->fecha_ultimo_aporte ? $cuenta->fecha_ultimo_aporte : date('Y-m-01');
        $inicio = new DateTime( $fecha_ultimo );
        $inicio->modify( 'first day of next month' );
        $hoy = new DateTime(); 

        while ( $inicio <= $hoy ) {
            $debe_ahorro += $valor_cuota_ahorro;
            $debe_secretaria += $valor_cuota_secretaria;
            
            $limite = clone $inicio;
            $limite->setDate( $inicio->format('Y'), $inicio->format('m'), 5 );
            
            if ( $hoy > $limite ) {
                $dias = $hoy->diff($limite)->days;
                $debe_multa += ($dias * 1000 * $acciones);
            }
            $inicio->modify( 'first day of next month' );
        }

        // --- 2. DEUDA DE CRÉDITOS (CAPITAL + INTERESES) ---
        $creditos_activos = $wpdb->get_results( $wpdb->prepare("SELECT * FROM {$wpdb->prefix}fondo_creditos WHERE user_id = %d AND estado IN ('activo', 'mora')", $user_id) );
        
        $deuda_capital = 0;
        $deuda_interes_corriente = 0;
        $deuda_interes_mora = 0;

        foreach ($creditos_activos as $credito) {
            $saldo = floatval($credito->saldo_actual);
            $deuda_capital += $saldo;

            // LÓGICA CRÉDITO ÁGIL
            if ( $credito->tipo_credito == 'agil' && $saldo > 0 ) {
                // A. Interés Corriente (1.5% del saldo actual)
                // Se debe pagar siempre que haya saldo.
                $deuda_interes_corriente += ($saldo * 0.015);

                // B. Interés Mora (4% si está vencido)
                $f_aprob = new DateTime($credito->fecha_aprobacion);
                $f_venc = clone $f_aprob;
                $f_venc->modify('+1 month'); // Vence al mes exacto

                if ( $hoy > $f_venc ) {
                    $dias_tarde = $hoy->diff($f_venc)->days;
                    // Fórmula: Saldo * 4% * (Días Tarde / 30)
                    $mora_calculada = $saldo * 0.04 * ($dias_tarde / 30);
                    $deuda_interes_mora += $mora_calculada;
                }
            }
            // Nota: Para créditos corrientes, el interés ya suele estar en la cuota fija o tabla de amortización, 
            // pero aquí nos enfocamos en el Ágil como pediste.
        }

        return [
            'ahorro' => $debe_ahorro,
            'secretaria' => $debe_secretaria,
            'multa' => $debe_multa,
            
            // Desglose Créditos
            'creditos_capital' => $deuda_capital,
            'creditos_interes' => $deuda_interes_corriente,
            'creditos_mora' => $deuda_interes_mora,
            
            // Totales para sugerencias
            'creditos' => $deuda_capital + $deuda_interes_corriente + $deuda_interes_mora,
            'total_admin' => $debe_ahorro + $debe_secretaria + $debe_multa,
            'cuenta_obj' => $cuenta
        ];
    }

    /**
     * Dibuja el formulario de reporte de pago en el frontend.
     *
     * Incluye sugerencias de monto mínimo y restricciones de captura de comprobante.
     */
    public function render_form_pago() {
        if ( ! is_user_logged_in() ) return '<p class="lud-alert error">Por favor inicia sesión.</p>';

        $user_id = get_current_user_id();
        $info_deuda = $this->calcular_deuda_usuario($user_id);
        
        if ( !$info_deuda ) return '<p>No tienes cuenta activa.</p>';

        $cuenta = $info_deuda['cuenta_obj'];
        $tiene_creditos = ($info_deuda['creditos'] > 0);
        
        // --- 1. LÓGICA DE CÁMARA (Anti-Fraude) ---
        // Por defecto (0) obligamos cámara trasera. Si es 1 (Alemania/Permiso), permitimos galería.
        $permite_galeria = ( isset($cuenta->permite_galeria) && $cuenta->permite_galeria == 1 );
        
        $input_file_attrs = 'accept="image/*"'; 
        $texto_ayuda = '';

        if ( ! $permite_galeria ) {
            // USUARIO LOCAL: FORZAR CÁMARA
            $input_file_attrs .= ' capture="environment"';
            $texto_ayuda = '📸 <strong>Cámara Obligatoria:</strong> Toma una foto clara del recibo al momento de pagar.';
        } else {
            // USUARIO EXTERIOR: PERMITIR ARCHIVOS
            $texto_ayuda = '📂 Puedes subir una foto o captura de pantalla del comprobante.';
        }

        // --- 2. CÁLCULO DE SUGERENCIAS ---
        $total_deuda_minima = $info_deuda['total_admin'];
        
        // Si no debe nada administrativo (está al día), sugerimos pagar el mes en curso por adelantado
        // o si ya pagó el mes, sugerimos 0.
        if ( $total_deuda_minima == 0 ) {
            // Opcional: Podríamos sugerir la cuota del mes actual si aún no ha vencido pero quiere pagar ya.
            // Por simplicidad, dejamos que el usuario decida, pero validaremos el tope en el backend.
            $placeholder_monto = "0";
        } else {
            $placeholder_monto = $total_deuda_minima;
        }

        $sugerencia_html = '';
        if ( $total_deuda_minima > 0 ) {
            $breakdown = "Ahorro ($" . number_format($info_deuda['ahorro']) . ") + Secr. ($" . number_format($info_deuda['secretaria']) . ")";
            if ($info_deuda['multa'] > 0) $breakdown .= " + Multas ($" . number_format($info_deuda['multa']) . ")";
            
            $sugerencia_html = "<div class='lud-suggestion'>
                <div style='font-size:0.85rem; text-transform:uppercase; font-weight:700;'>💡 Debes pagar al menos</div>
                <div class='lud-sug-amount'>$ " . number_format($total_deuda_minima, 0, ',', '.') . "</div>
                <div style='font-size:0.85rem;'>$breakdown</div>
            </div>";
        } else {
            $sugerencia_html = "<div class='lud-success-box'>✅ <strong>Estás al día.</strong> Solo realiza pagos si deseas adelantar cuota o abonar a tu crédito.</div>";
        }

        $msg = '';
        if ( isset( $_GET['lud_status'] ) && $_GET['lud_status'] == 'success' ) {
            $msg = '<div class="lud-alert success">✅ <strong>¡Recibido!</strong> Comprobante enviado y reporte registrado.</div>';
        } elseif ( isset( $_GET['lud_error'] ) ) {
             $msg = '<div class="lud-alert error">❌ ' . sanitize_text_field($_GET['lud_error']) . '</div>';
        }

        ob_start();
        ?>
        <div class="lud-card">
            <div class="lud-header"><h3>Reportar Pago</h3></div>
            <?php echo $msg; ?>
            <?php echo $sugerencia_html; ?>
            
            <form action="<?php echo esc_url( admin_url('admin-post.php') ); ?>" method="POST" enctype="multipart/form-data">
                <input type="hidden" name="action" value="lud_procesar_pago">
                <?php wp_nonce_field( 'lud_pago_nonce', 'lud_security' ); ?>

                <div class="lud-form-group">
                    <label class="lud-label">Monto a Entregar ($)</label>
                    <input type="number" name="monto" id="input_monto" class="lud-input" placeholder="0" min="1000" required 
                           value="<?php echo ($total_deuda_minima > 0) ? $total_deuda_minima : ''; ?>">
                </div>

                <div class="lud-form-group">
                    <label class="lud-label">Foto del Comprobante</label>
                    <small style="color:#666; display:block; margin-bottom:8px; line-height:1.3; background:#f5f5f5; padding:8px; border-radius:6px;">
                        <?php echo $texto_ayuda; ?>
                    </small>
                    <input type="file" name="comprobante" class="lud-input" <?php echo $input_file_attrs; ?> required style="padding: 10px;">
                </div>

                <?php if ( $tiene_creditos ): ?>
                <div id="bloque_excedentes" style="display:none; background:#e8f5e9; padding:15px; border-radius:8px; margin-bottom:20px;">
                    <div class="lud-form-group" style="margin-bottom:0;">
                        <label class="lud-label" style="color:#2e7d32;">💵 Vas a abonar extra a tu crédito:</label>
                        <p style="font-size:0.85rem; margin-bottom:10px;">El dinero que sobre después de pagar tu cuota mensual se irá a tu deuda. ¿Cómo prefieres aplicarlo?</p>
                        
                        <div class="lud-radio-group">
                            <label class="lud-radio-card">
                                <input type="radio" name="preferencia_abono" value="reducir_cuota" checked>
                                <div><span class="lud-radio-title">📉 Bajar valor de Cuota</span></div>
                            </label>
                            <label class="lud-radio-card">
                                <input type="radio" name="preferencia_abono" value="reducir_plazo">
                                <div><span class="lud-radio-title">⏳ Reducir Plazo (Terminar antes)</span></div>
                            </label>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <div class="lud-form-group">
                    <label class="lud-label">Nota (Opcional)</label>
                    <textarea name="detalle" class="lud-input" rows="2" placeholder="Ej: Pago realizado en la tienda de Sara..."></textarea>
                </div>
                
                <button type="submit" class="lud-btn">Enviar pago</button>
            </form>
        </div>
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                var input = document.getElementById('input_monto');
                var box = document.getElementById('bloque_excedentes');
                var min = <?php echo floatval($total_deuda_minima); ?>;
                var tieneCredito = <?php echo $tiene_creditos ? 'true' : 'false'; ?>;

                function checkExcedente() {
                    if (!tieneCredito) return;
                    // Si paga más de lo que debe de cuota administrativa, mostramos la opción
                    if (parseFloat(input.value) > min) {
                        box.style.display = 'block';
                    } else {
                        box.style.display = 'none';
                    }
                }
                
                input.addEventListener('input', checkExcedente);
                checkExcedente(); // Check inicial
            });
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Procesa el envío del formulario de pago y registra la transacción.
     */
    public function procesar_pago() {
        if ( ! isset( $_POST['lud_security'] ) || ! wp_verify_nonce( $_POST['lud_security'], 'lud_pago_nonce' ) ) wp_die('Seguridad');
        if ( ! is_user_logged_in() ) wp_die('Login requerido');

        global $wpdb;
        $user_id = get_current_user_id();
        $monto = floatval( $_POST['monto'] );
        $pref = isset($_POST['preferencia_abono']) ? sanitize_text_field( $_POST['preferencia_abono'] ) : 'general';
        $nota = sanitize_textarea_field( $_POST['detalle'] );

        // --- 1. VALIDACIÓN ESTRICTA DE MONTO MÁXIMO ---
        $info = $this->calcular_deuda_usuario($user_id);
        
        // Deuda Administrativa Total (Lo vencido + Lo del mes siguiente si quiere adelantar)
        // Para ser flexibles pero seguros: Permitimos pagar la deuda vencida + 1 cuota mensual completa
        $acciones = intval($info['cuenta_obj']->numero_acciones);
        $cuota_mes_total = ($acciones * 50000) + ($acciones * 1000); 
        
        // El usuario puede pagar: (Todo lo que debe atrasado) + (Sus créditos completos) + (La cuota de este mes si no la ha pagado)
        // Si total_admin es 0, significa que está al día, así que le permitimos pagar la cuota de este mes ($cuota_mes_total).
        // Si total_admin > 0, eso ya incluye la deuda.
        
        $base_permitida = ($info['total_admin'] > 0) ? $info['total_admin'] : $cuota_mes_total;
        
        // Tope Máximo Absoluto = (Deuda Admin) + (Deuda Total Créditos) + (Margen error $1.000)
        $maximo_pagable = $base_permitida + $info['creditos'] + 1000;

        if ( $monto > $maximo_pagable ) {
            $error_msg = '<h3>❌ Pago Rechazado</h3>';
            $error_msg .= '<p>Estás intentando pagar <b>$'.number_format($monto).'</b>.</p>';
            $error_msg .= '<p>Tu deuda total (Ahorro + Créditos) es de <b>$'.number_format($maximo_pagable - 1000).'</b>.</p>';
            $error_msg .= '<p>Por estatutos, <b>no se permite el "Ahorro Extra"</b> o voluntario. Solo puedes pagar lo que debes o abonar a tus créditos vigentes.</p>';
            
            wp_die('<div class="lud-alert error">'.$error_msg.'<br><a href="javascript:history.back()">Volver e intentar de nuevo</a></div>');
        }

        // --- 2. PROCESAMIENTO DE IMAGEN ---
        $filename_sql = ''; 
        if ( isset($_FILES['comprobante']) && !empty($_FILES['comprobante']['name']) ) {
            $file = $_FILES['comprobante'];
            $allowed_types = array(
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'webp' => 'image/webp',
            );

            if ( $file['error'] !== UPLOAD_ERR_OK ) {
                wp_redirect( add_query_arg( 'lud_error', 'Error al subir el comprobante.', wp_get_referer() ) );
                exit;
            }

            if ( $file['size'] > 2 * 1024 * 1024 ) {
                wp_redirect( add_query_arg( 'lud_error', 'El comprobante supera los 2MB permitidos.', wp_get_referer() ) );
                exit;
            }

            $filetype = wp_check_filetype_and_ext( $file['tmp_name'], $file['name'], $allowed_types );
            if ( empty($filetype['ext']) || empty($filetype['type']) || ! array_key_exists( $filetype['ext'], $allowed_types ) ) {
                wp_redirect( add_query_arg( 'lud_error', 'Formato no permitido. Solo JPG/PNG/WEBP.', wp_get_referer() ) );
                exit;
            }
            
            $upload_dir = wp_upload_dir();
            $target_dir = $upload_dir['basedir'] . '/fondo_seguro/';
            if ( ! file_exists( $target_dir ) ) wp_mkdir_p( $target_dir );
            
            $new_filename = sanitize_file_name( 'pago_' . $user_id . '_' . time() . '.' . $filetype['ext'] );
            $target_path = $target_dir . $new_filename;
            
            if ( move_uploaded_file( $file['tmp_name'], $target_path ) ) {
                $filename_sql = $new_filename;
            } else {
                wp_die('Error al guardar la imagen en el servidor seguro.');
            }
        } else {
            wp_redirect( add_query_arg( 'lud_error', 'La foto del comprobante es obligatoria.', wp_get_referer() ) );
            exit;
        }

        // --- 3. GUARDADO CON ETIQUETA DE PREFERENCIA ---
        $pref_texto = '';
        if ( $info['creditos'] > 0 && $monto > $base_permitida ) {
            // Solo añadimos la etiqueta si realmente hay un excedente que se irá al crédito
            $pref_texto = ($pref == 'reducir_cuota') ? 'Pref: [BAJAR CUOTA]' : 'Pref: [REDUCIR PLAZO]';
        }
        
        $detalle_final = trim($pref_texto . " | " . $nota, " | ");

        $wpdb->insert(
            $wpdb->prefix . 'fondo_transacciones',
            array(
                'user_id' => $user_id,
                'tipo' => 'pago_consolidado',
                'monto' => $monto,
                'estado' => 'pendiente',
                'detalle' => $detalle_final,
                'comprobante_url' => $filename_sql, 
                'fecha_registro' => current_time( 'mysql' )
            ),
            array( '%d', '%s', '%f', '%s', '%s', '%s', '%s' )
        );

        wp_redirect( add_query_arg( 'lud_status', 'success', remove_query_arg( array('lud_status','lud_error'), wp_get_referer() ) ) );
        exit;
    }
}
